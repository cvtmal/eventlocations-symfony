<?php

declare(strict_types=1);

namespace App\Venues\Controller;

use App\Shared\Security\ClientIpKey;
use App\Shared\Uid\EntityId;
use App\Venues\Command\ReportContent;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Form\ReportContentData;
use App\Venues\Form\ReportContentType;
use App\Venues\Mailer\ModerationMailer;
use App\Venues\ReadModel\ModerationCaseQuery;
use App\Venues\ReadModel\ReportableVenue;
use App\Venues\ReadModel\ReportableVenueQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `/inhalt-melden` (G2): the public complaint form, without an account. `?location=<number>` of a published venue
 * shows the venue and, for a rights complaint, its public photos to tick; anything else (no number, an unknown or
 * unpublished one) shows the form with a free-text "Welche Location?".
 *
 * A valid submit stores the case (`ReportContent`, which hides the named photos at once), then mails a receipt to
 * the complainant and a notice to staff, and answers with a 303 to a signed confirmation of the same page: public
 * pages have no session, so there is no flash. Checks in the inquiry form's order: the honeypot `website` (the same
 * 303, nothing stored, so a bot learns nothing), the form (422), then the limiter `content_report` (5 per hour per
 * client IP, only valid posts count; 429 + `Retry-After`). A complaint that names photos then spends one token of
 * `content_report_holds` (20 per day, site-wide): when none is left the case is stored and mailed but hides nothing,
 * and the staff notice says so. Never cached, never indexed, no session, no cookie.
 */
final class ReportContentController extends AbstractController
{
    public const string SENT_FLAG = 'gesendet';

    private const array HEADERS = ['X-Robots-Tag' => 'noindex, nofollow'];

    /** `content_report_holds` counts for the whole site, not per client. */
    private const string HOLDS_KEY = 'site';

    public function __construct(
        private readonly ReportableVenueQuery $venues,
        private readonly ModerationCaseQuery $cases,
        private readonly ModerationMailer $mailer,
        private readonly MessageBusInterface $commandBus,
        private readonly RateLimiterFactoryInterface $contentReportLimiter,
        private readonly RateLimiterFactoryInterface $contentReportHoldsLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly UriSigner $signer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['de' => '/inhalt-melden'], name: 'venue_report', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $venue = $this->venue($request);
        if ($request->isMethod('GET') && $request->query->has(self::SENT_FLAG) && $this->signer->checkRequest($request)) {
            return $this->private($this->render('venues/report/show.html.twig', ['venue' => $venue, 'form' => null, 'sent' => true]));
        }

        $data = new ReportContentData();
        $data->hasVenue = null !== $venue;
        $data->source = $this->source($request, $venue);
        $form = $this->createForm(ReportContentType::class, $data, [
            'photo_ids' => $venue?->photoIds() ?? [],
            'has_venue' => null !== $venue,
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->page($venue, $form, Response::HTTP_OK);
        }
        $honeypot = $form->get('website')->getData();
        if (\is_string($honeypot) && '' !== trim($honeypot)) {
            return $this->confirmation($venue);
        }
        if (!$form->isValid()) {
            return $this->page($venue, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $this->contentReportLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $form->addError(new FormError($this->translator->trans('report.error.rate_limited', [], 'venues')));
            $response = $this->page($venue, $form, Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        // Only a complaint that would hide photos spends the site-wide daily budget; over it, nothing is hidden.
        $namesPhotos = null !== $venue && true === $data->kind?->namesPhotos() && [] !== $data->photos;
        $hold = !$namesPhotos || $this->contentReportHoldsLimiter->create(self::HOLDS_KEY)->consume()->isAccepted();

        $caseId = EntityId::generate();
        try {
            $this->commandBus->dispatch($this->command($caseId, $data, $venue, $hold));
        } catch (HandlerFailedException $failure) {
            if ([] === $failure->getWrappedExceptions(VenueNotFound::class)) {
                throw $failure;
            }
            // The venue was deleted between the page and the submit: ask again, without it. The same answers go into
            // the form without a venue (no photos to tick, a "Welche Location?" to fill), so nothing typed is lost.
            $retry = new ReportContentData();
            $form = $this->createForm(ReportContentType::class, $retry);
            $answers = $request->request->all($form->getName());
            unset($answers['photos']);
            $form->submit($answers);
            $form->addError(new FormError($this->translator->trans('report.error.venue_gone', [], 'venues')));

            return $this->page(null, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $case = $this->cases->find($caseId);
        if (null !== $case) {
            $this->mailer->sendReceipt($case);
            $this->mailer->sendNotice($case, holdLimitReached: !$hold);
        }

        return $this->confirmation($venue);
    }

    private function command(string $caseId, ReportContentData $data, ?ReportableVenue $venue, bool $hold): ReportContent
    {
        \assert(null !== $data->kind && null !== $data->message && null !== $data->name && null !== $data->email);
        $reference = null === $data->venueReference ? null : trim($data->venueReference);

        return new ReportContent(
            $caseId,
            $data->kind,
            $venue?->number,
            null === $venue && '' !== $reference ? $reference : null,
            null !== $venue && $data->kind->namesPhotos() ? $data->photos : [],
            trim($data->name),
            trim($data->email),
            $data->message,
            $data->source,
            $hold,
        );
    }

    /**
     * The published venue of `?location=`, or null for anything else: the form then asks which location is meant.
     */
    private function venue(Request $request): ?ReportableVenue
    {
        $location = $request->query->get('location');
        if (!\is_string($location) || 1 !== preg_match('/^[1-9][0-9]{0,9}$/D', $location)) {
            return null;
        }

        return $this->venues->find((int) $location, $request->getLocale());
    }

    /**
     * The page the form was opened from: the referring path of this site, else the venue's own page. Kept in a
     * hidden field, so the submit carries what the first view saw.
     */
    private function source(Request $request, ?ReportableVenue $venue): ?string
    {
        $referer = $request->headers->get('Referer');
        if (null !== $referer && $request->isMethod('GET')) {
            $parts = parse_url($referer);
            if (\is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && isset($parts['path'])) {
                $path = $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
                if (\strlen($path) <= 500 && 1 === preg_match(ReportContentData::SOURCE_SHAPE, $path)) {
                    return $path;
                }
            }
        }

        return null === $venue ? null : $this->generateUrl('venue_show', ['slug' => $venue->slug]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(?ReportableVenue $venue, FormInterface $form, int $status): Response
    {
        return $this->private($this->render('venues/report/show.html.twig', [
            'venue' => $venue,
            'form' => $form->createView(),
            'sent' => false,
        ], new Response(status: $status)));
    }

    private function confirmation(?ReportableVenue $venue): Response
    {
        $query = [self::SENT_FLAG => '1'] + (null === $venue ? [] : ['location' => $venue->number]);
        $url = $this->generateUrl('venue_report', $query, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->private(new RedirectResponse($this->signer->sign($url), Response::HTTP_SEE_OTHER));
    }

    private function private(Response $response): Response
    {
        $response->headers->add(self::HEADERS);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
