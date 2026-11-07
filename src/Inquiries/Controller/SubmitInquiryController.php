<?php

declare(strict_types=1);

namespace App\Inquiries\Controller;

use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\Event\InquiryEvents;
use App\Inquiries\Exception\EventDateInPast;
use App\Inquiries\Exception\GuestCountOutOfRange;
use App\Inquiries\Exception\InquiryNotAccepted;
use App\Inquiries\Form\FillTimeToken;
use App\Inquiries\Form\InquiryData;
use App\Inquiries\Form\InquiryFormFactory;
use App\Inquiries\Twig\InquiryFormExtension;
use App\Inquiries\ValueObject\DeliveryOutcome;
use App\Inquiries\ValueObject\SubmittedInquiry;
use App\Shared\Exception\DomainException;
use App\Shared\Security\ClientIpKey;
use App\Venues\Controller\VenueDetailController;
use App\Venues\ReadModel\VenueDetail;
use App\Venues\ReadModel\VenueDetailQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `POST /eventlokal/{slug}/anfrage` (DP-13, IQ-1 to IQ-3). Stores the inquiry, then
 * delivers it, each in its own transaction, then publishes `InquirySubmitted`. The guest gets
 * a 303 to a signed, constant confirmation URL on the cached detail page; an error re-renders
 * that page with the values kept. A history slug is accepted: the form may be on a cached copy.
 *
 * The same inquiry sent twice takes the same path. `SubmitInquiry` returns the row it repeats,
 * `DeliverInquiry` waits for a delivery in progress and sends only what is missing, so the repeat
 * answers with the real outcome: the 303 if the mail went out, the 503 if it did not. Only a new
 * row is published.
 *
 * A filled honeypot gets the same 303 with nothing stored, so a bot learns nothing. The limiter
 * counts only what passed validation and the fill time, so typos cost a person nothing.
 *
 * Never cached, never indexed, no session.
 */
final class SubmitInquiryController extends AbstractController
{
    private const HEADERS = ['X-Robots-Tag' => 'noindex, nofollow'];
    private const ERROR_HEADERS = self::HEADERS + ['Cache-Control' => 'private, no-store'];

    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly InquiryFormFactory $forms,
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $inquirySubmitLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly FillTimeToken $fillTime,
        private readonly InquiryEvents $events,
        private readonly UriSigner $signer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['de' => '/eventlokal/{slug}/anfrage'], name: 'inquiry_submit', requirements: ['slug' => VenueDetailController::SLUG_REQUIREMENT], methods: ['POST'])]
    public function __invoke(Request $request, string $slug): Response
    {
        $locale = $request->getLocale();
        $match = $this->venues->resolveSlug($slug, $locale) ?? throw new NotFoundHttpException(headers: self::ERROR_HEADERS);
        if (!$match->isPublished) {
            throw new GoneHttpException(headers: self::ERROR_HEADERS);
        }
        $venue = $this->venues->detail($match->number, $locale);
        if (null === $venue || null === $venue->inquiryEmail) {
            throw new NotFoundHttpException(headers: self::ERROR_HEADERS);
        }

        $form = $this->forms->create($venue);
        $form->handleRequest($request);

        $honeypot = $form->get('website')->getData();
        if (\is_string($honeypot) && '' !== trim($honeypot)) {
            return $this->confirmation($venue);
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->page($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $started = $form->get('started')->getData();
        if (!$this->fillTime->isHuman(\is_string($started) ? $started : null)) {
            $form->addError($this->error('form.error.too_fast'));

            return $this->page($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $this->inquirySubmitLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $form->addError($this->error('form.error.rate_limited'));
            $response = $this->page($venue, $form, Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        try {
            $inquiry = $this->commandBus->dispatch($this->command($form->getData(), $venue, $locale))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            $this->addDomainError($form, $exception);

            return $this->page($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        \assert($inquiry instanceof SubmittedInquiry);

        // In `finally` so that every stored inquiry is published, also on an unexpected delivery error.
        try {
            $outcome = $this->commandBus->dispatch(new DeliverInquiry($inquiry->id))->last(HandledStamp::class)?->getResult();
            \assert($outcome instanceof DeliveryOutcome);
        } finally {
            if (!$inquiry->isRepeat) {
                $this->events->submitted($inquiry->id);
            }
        }

        if (!$outcome->venueMailSent) {
            $form->addError($this->error('form.error.delivery_failed'));

            return $this->page($venue, $form, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->confirmation($venue);
    }

    private function command(mixed $data, VenueDetail $venue, string $locale): SubmitInquiry
    {
        \assert($data instanceof InquiryData);
        \assert(null !== $data->occasion && null !== $data->guests && null !== $data->message);
        \assert(null !== $data->firstName && null !== $data->lastName && null !== $data->email);

        return new SubmitInquiry(
            $venue->id,
            $venue->slug,
            $locale,
            $data->occasion->value,
            $data->guests,
            $data->eventDate?->format('Y-m-d'),
            $data->message,
            $data->firstName,
            $data->lastName,
            $data->email,
            $data->phone,
        );
    }

    /**
     * @throws HandlerFailedException when it wraps anything but this form's business rules
     */
    private function addDomainError(FormInterface $form, HandlerFailedException $exception): void
    {
        $domainException = array_values($exception->getWrappedExceptions(DomainException::class))[0] ?? throw $exception;

        match (true) {
            $domainException instanceof GuestCountOutOfRange => $form->get('guests')->addError($this->error('form.error.guests_range')),
            $domainException instanceof EventDateInPast => $form->get('eventDate')->addError($this->error('form.error.date_past')),
            $domainException instanceof InquiryNotAccepted => $form->addError($this->error('form.error.not_accepted')),
            default => throw $exception,
        };
    }

    private function error(string $key): FormError
    {
        return new FormError($this->translator->trans($key, [], 'inquiries'));
    }

    private function page(VenueDetail $venue, FormInterface $form, int $status): Response
    {
        return self::private($this->render('venues/detail/show.html.twig', [
            'venue' => $venue,
            'inquiry' => $form->createView(),
        ], new Response(status: $status)));
    }

    /**
     * The fragment goes on after signing: UriSigner hashes it, and the browser does not send
     * it back, so a signed fragment could never be verified.
     */
    private function confirmation(VenueDetail $venue): Response
    {
        $url = $this->generateUrl('venue_show', ['slug' => $venue->slug] + InquiryFormExtension::confirmationQuery(), UrlGeneratorInterface::ABSOLUTE_URL);

        return self::private(new RedirectResponse($this->signer->sign($url).'#anfrage', Response::HTTP_SEE_OTHER));
    }

    private static function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
