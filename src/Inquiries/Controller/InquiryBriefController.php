<?php

declare(strict_types=1);

namespace App\Inquiries\Controller;

use App\Inquiries\Command\ConfirmInquiryBrief;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\Command\SubmitInquiryBrief;
use App\Inquiries\Event\InquiryEvents;
use App\Inquiries\Exception\BriefNotAccepted;
use App\Inquiries\Exception\BudgetOutOfRange;
use App\Inquiries\Exception\EventDateInPast;
use App\Inquiries\Exception\EventDateMissing;
use App\Inquiries\Exception\GuestCountOutOfRange;
use App\Inquiries\Exception\UnknownInquiryTerm;
use App\Inquiries\Form\BriefFormFactory;
use App\Inquiries\Form\FillTimeToken;
use App\Inquiries\Form\InquiryData;
use App\Inquiries\ReadModel\BriefCandidate;
use App\Inquiries\ReadModel\BriefCandidatesQuery;
use App\Inquiries\ReadModel\BriefQuery;
use App\Inquiries\ValueObject\BriefOutcome;
use App\Inquiries\ValueObject\BriefRules;
use App\Inquiries\ValueObject\DeliveryOutcome;
use App\Inquiries\ValueObject\SubmittedBrief;
use App\Shared\Exception\DomainException;
use App\Shared\Security\ClientIpKey;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `/sammelanfrage?nr=…` (F13, D17): one brief to two to five venues from the Merkliste or a result list. `nr` is the
 * venue numbers in order; the page offers the ones that take briefs (published, an inquiry address, not opted out,
 * under today's cap) as named checkboxes and lists the others as "nur einzeln anfragbar", never as a choice.
 *
 * The POST runs the single inquiry's checks in the same order (honeypot, form, fill time, the shared `inquiry_submit`
 * limiter, one token per brief), stores the brief and its children in one transaction, delivers each child in its
 * own, publishes `InquirySubmitted` per new child, then sends the one confirmation. A venue that stopped taking briefs
 * between page and POST refuses the whole brief (422, the current choice shown again), so only venues the guest saw
 * ticked get mail. When no venue mail went out the guest gets the G7 «gespeichert» state (503); otherwise a 303 to a
 * signed page naming the venues reached.
 *
 * Never cached, never indexed, no session, no cookie.
 */
final class InquiryBriefController extends AbstractController
{
    private const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow'];

    public function __construct(
        private readonly BriefCandidatesQuery $candidates,
        private readonly BriefQuery $briefs,
        private readonly BriefFormFactory $forms,
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $inquirySubmitLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly FillTimeToken $fillTime,
        private readonly InquiryEvents $events,
        private readonly UriSigner $signer,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: ['de' => '/sammelanfrage'], name: 'inquiry_brief', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $locale = $request->getLocale();
        $numbers = BriefRules::numbers($request->query->all()['nr'] ?? null);
        [$offered, $notOffered] = $this->candidates($numbers, $locale);
        if (\count($offered) < BriefRules::MIN_VENUES) {
            // A state of the page, not an error of the guest's input: the same answer for GET and POST.
            return $this->page($numbers, $offered, $notOffered, null);
        }

        $form = $this->forms->create($offered, $numbers);
        if (!$request->isMethod('POST')) {
            return $this->page($numbers, $offered, $notOffered, $form);
        }

        $form->handleRequest($request);
        $honeypot = $form->get('website')->getData();
        if (\is_string($honeypot) && '' !== trim($honeypot)) {
            return self::private(new RedirectResponse($this->generateUrl('inquiry_brief', ['nr' => implode(',', $numbers)]), Response::HTTP_SEE_OTHER));
        }
        if (!$form->isSubmitted() || !$form->isValid()) {
            // A ticked venue that stopped taking briefs since the page is no choice any more: say so, not "invalid".
            $ticked = BriefRules::numbers($request->request->all()[$form->getName()]['venues'] ?? null);
            $stillOffered = array_map(static fn (BriefCandidate $candidate): int => $candidate->number, $offered);
            if ($form->isSubmitted() && [] !== array_diff($ticked, $stillOffered)) {
                $data = $form->getData();
                \assert($data instanceof InquiryData);

                return $this->changed($numbers, $offered, $notOffered, $data, $ticked);
            }

            return $this->page($numbers, $offered, $notOffered, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $started = $form->get('started')->getData();
        if (!$this->fillTime->isHuman(\is_string($started) ? $started : null)) {
            $form->addError($this->error('form.error.too_fast'));

            return $this->page($numbers, $offered, $notOffered, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $limit = $this->inquirySubmitLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $form->addError($this->error('form.error.rate_limited'));
            $response = $this->page($numbers, $offered, $notOffered, $form, Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $data = $form->getData();
        \assert($data instanceof InquiryData);
        /** @var list<int> $ticked */
        $ticked = $form->get('venues')->getData();
        try {
            $brief = $this->commandBus->dispatch($this->command($data, $ticked, $offered, $locale))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            $domainException = array_values($exception->getWrappedExceptions(DomainException::class))[0] ?? throw $exception;
            if ($domainException instanceof BriefNotAccepted) {
                [$offered, $notOffered] = $this->candidates($numbers, $locale);

                return $this->changed($numbers, $offered, $notOffered, $data, $ticked);
            }
            $this->addDomainError($form, $domainException, $exception);

            return $this->page($numbers, $offered, $notOffered, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        \assert($brief instanceof SubmittedBrief);

        $reached = $this->deliver($brief);
        try {
            $outcome = $this->commandBus->dispatch(new ConfirmInquiryBrief($brief->id))->last(HandledStamp::class)?->getResult();
            \assert($outcome instanceof BriefOutcome);
            $reached = $outcome->reachedAny();
        } catch (\Throwable $exception) {
            $this->logUnexpected('Brief {inquiry}: the confirmation ended unexpectedly ({exception_class} at {file}:{line}).', $brief->id, $exception);
        }

        if (!$reached) {
            return $this->page($numbers, $offered, $notOffered, null, Response::HTTP_SERVICE_UNAVAILABLE, undelivered: true);
        }
        $url = $this->generateUrl('inquiry_brief_sent', ['brief' => $brief->id], UrlGeneratorInterface::ABSOLUTE_URL);

        return self::private(new RedirectResponse($this->signer->sign($url), Response::HTTP_SEE_OTHER));
    }

    /**
     * The guest's confirmation after a brief, on a signed URL: it names venues, so only the sender's redirect opens it.
     */
    #[Route(path: ['de' => '/sammelanfrage/gesendet'], name: 'inquiry_brief_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        $brief = $request->query->getString('brief');
        if (!$this->signer->checkRequest($request) || !Uuid::isValid($brief)) {
            throw new NotFoundHttpException(headers: self::HEADERS + ['Cache-Control' => 'private, no-store']);
        }
        $recipients = $this->briefs->recipients($brief);
        if ([] === $recipients) {
            throw new NotFoundHttpException(headers: self::HEADERS + ['Cache-Control' => 'private, no-store']);
        }

        return self::private($this->render('inquiries/brief_sent.html.twig', ['recipients' => $recipients]));
    }

    /**
     * Delivers each child in its own transaction and publishes every new child, also after an unexpected error.
     *
     * @return bool whether any venue mail went out
     */
    private function deliver(SubmittedBrief $brief): bool
    {
        $reached = false;
        try {
            foreach ($brief->inquiryIds as $inquiryId) {
                try {
                    $outcome = $this->commandBus->dispatch(new DeliverInquiry($inquiryId))->last(HandledStamp::class)?->getResult();
                    \assert($outcome instanceof DeliveryOutcome);
                    $reached = $reached || $outcome->venueMailSent;
                } catch (\Throwable $exception) {
                    $this->logUnexpected('Inquiry {inquiry}: delivery ended unexpectedly ({exception_class} at {file}:{line}).', $inquiryId, $exception);
                }
            }
        } finally {
            if (!$brief->isRepeat) {
                foreach ($brief->inquiryIds as $inquiryId) {
                    $this->events->submitted($inquiryId);
                }
            }
        }

        return $reached;
    }

    /**
     * @param list<int> $numbers
     *
     * @return array{list<BriefCandidate>, list<BriefCandidate>} offered (at most `MAX_CANDIDATES`), not offered
     */
    private function candidates(array $numbers, string $locale): array
    {
        $all = $this->candidates->forNumbers($numbers, $locale, $this->clock->now());
        $offered = array_values(array_filter($all, static fn (BriefCandidate $candidate): bool => $candidate->offered));

        return [
            \array_slice($offered, 0, BriefRules::MAX_CANDIDATES),
            array_values(array_filter($all, static fn (BriefCandidate $candidate): bool => !$candidate->offered)),
        ];
    }

    /**
     * @param list<int>            $ticked
     * @param list<BriefCandidate> $offered
     */
    private function command(InquiryData $data, array $ticked, array $offered, string $locale): SubmitInquiryBrief
    {
        \assert(null !== $data->eventType && null !== $data->guests && null !== $data->message);
        \assert(null !== $data->firstName && null !== $data->lastName && null !== $data->email);
        $venues = [];
        foreach ($offered as $candidate) {
            if (\in_array($candidate->number, $ticked, true)) {
                $venues[$candidate->id] = $candidate->slug;
            }
        }

        return new SubmitInquiryBrief(
            $venues,
            $locale,
            $data->eventType,
            $data->company,
            $data->guests,
            $data->eventDate?->format('Y-m-d'),
            $data->dateFlexible,
            $data->startTime?->format('H:i'),
            $data->cateringWish,
            $data->budgetChf,
            $data->message,
            $data->firstName,
            $data->lastName,
            $data->email,
            $data->phone,
        );
    }

    /**
     * The 422 of a brief a venue refused since the page: the current choice again, the guest's ticks kept where still
     * offered, and why.
     *
     * @param list<int>            $numbers
     * @param list<BriefCandidate> $offered
     * @param list<BriefCandidate> $notOffered
     * @param list<int>            $ticked
     */
    private function changed(array $numbers, array $offered, array $notOffered, InquiryData $data, array $ticked): Response
    {
        $form = \count($offered) < BriefRules::MIN_VENUES ? null : $this->forms->create($offered, $numbers, $data, $ticked);
        $form?->addError($this->error('brief.error.changed'));

        return $this->page($numbers, $offered, $notOffered, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @throws HandlerFailedException when it wraps anything but this form's business rules
     */
    private function addDomainError(FormInterface $form, DomainException $domainException, HandlerFailedException $exception): void
    {
        match (true) {
            $domainException instanceof GuestCountOutOfRange => $form->get('guests')->addError($this->error('form.error.guests_range')),
            $domainException instanceof EventDateInPast => $form->get('eventDate')->addError($this->error('form.error.date_past')),
            $domainException instanceof EventDateMissing => $form->get('eventDate')->addError($this->error('form.error.date_missing')),
            $domainException instanceof BudgetOutOfRange => $form->get('budgetChf')->addError($this->error('form.error.budget_range')),
            $domainException instanceof UnknownInquiryTerm => $form->addError($this->error('form.error.unknown_term')),
            default => throw $exception,
        };
    }

    private function error(string $key): FormError
    {
        return new FormError($this->translator->trans($key, [], 'inquiries'));
    }

    private function logUnexpected(string $message, string $id, \Throwable $exception): void
    {
        $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
        $this->logger->error($message, [
            'inquiry' => $id,
            'exception_class' => $cause::class,
            'file' => $cause->getFile(),
            'line' => $cause->getLine(),
        ]);
    }

    /**
     * @param list<int>            $numbers
     * @param list<BriefCandidate> $offered
     * @param list<BriefCandidate> $notOffered
     */
    private function page(array $numbers, array $offered, array $notOffered, ?FormInterface $form, int $status = Response::HTTP_OK, bool $undelivered = false): Response
    {
        return self::private($this->render('inquiries/brief.html.twig', [
            'numbers' => $numbers,
            'offered' => $offered,
            'not_offered' => $notOffered,
            'form' => $form?->createView(),
            'undelivered' => $undelivered,
        ], new Response(status: $status)));
    }

    private static function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
