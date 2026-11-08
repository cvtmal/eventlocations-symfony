<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Portal\Command\ResetPortalPassword;
use App\Portal\Command\StartClaim;
use App\Portal\Command\VerifyClaim;
use App\Portal\Exception\ClaimThrottled;
use App\Portal\Form\ClaimCodeData;
use App\Portal\Form\ClaimCodeType;
use App\Portal\Form\PortalPasswordData;
use App\Portal\Form\PortalPasswordType;
use App\Portal\Form\RegisterData;
use App\Portal\Form\RegisterType;
use App\Portal\Mailer\PortalMailer;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalLinkLogin;
use App\Portal\ValueObject\ClaimOutcome;
use App\Portal\ValueObject\ClaimVerification;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Security\ClientIpKey;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use App\Venues\PreviewToken;
use App\Venues\ReadModel\VenuePreviewLink;
use App\Venues\ReadModel\VenuePreviewQuery;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * G6: "Gratis übernehmen" and "Angaben korrigieren" on a preview page (G5) start a claim here. Four open steps:
 *
 * 1. `start` takes the preview token from the page's POST body (a token in a URL would reach the access log, Wave G
 *    §4a.4) and keeps only the link's id, venue and campaign in the session;
 * 2. the claimant's name and address; a code goes to that address, whatever account it has or lacks, so the answer
 *    tells nothing about accounts (the IP budget `portal_register` and `StartClaimHandler`'s per-address and
 *    per-venue limits hold for everyone alike);
 * 3. the code (30 minutes, five tries, `VerifyClaimHandler`): it proves the address, so the account of that address
 *    is found or created and logged in; access comes at once on the website's domain, anything else goes to staff;
 * 4. a first password, when that account has none (the code proved the mailbox, as a reset link would).
 *
 * Every response is `no-store` and `noindex` (`PortalResponseHeadersListener`); the code never leaves the mail.
 */
final class ClaimFlowController extends AbstractController
{
    private const string SESSION = 'portal_claim';

    public function __construct(
        private readonly PreviewToken $tokens,
        private readonly VenuePreviewQuery $previews,
        private readonly VenueHeaderQuery $venues,
        private readonly MessageBusInterface $commandBus,
        private readonly PortalMailer $mailer,
        private readonly PortalLinkLogin $login,
        private readonly RateLimiterFactoryInterface $portalRegisterLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/konto/uebernehmen/start', name: 'portal_claim_start', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function start(Request $request): Response
    {
        // No CSRF token can exist yet (the preview page has no session); a cross-site form would at least end a
        // signed-in manager's session, so only our own pages may post here. Older browsers send no header.
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && 'same-origin' !== $site) {
            return $this->gone('claim.gone.title', 'claim.gone.text', Response::HTTP_FORBIDDEN);
        }

        $token = $request->request->all()['token'] ?? null;
        $id = \is_string($token) ? $this->tokens->idFrom($token) : null;
        $link = null === $id ? null : $this->openLink($id);
        if (null === $link) {
            return $this->gone('claim.gone.title', 'claim.gone.text');
        }

        $request->getSession()->set(self::SESSION, [
            'link' => $link->id,
            'number' => $link->venueNumber,
            'correct' => 'correct' === ($request->request->all()['intent'] ?? null),
        ]);

        return $this->redirectToRoute('portal_claim', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/konto/uebernehmen', name: 'portal_claim', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function address(Request $request): Response
    {
        // An open route: a logged-in manager only gets the form filled in.
        $identity = $this->getUser();
        $state = $this->state($request->getSession());
        $link = null === $state || !isset($state['link']) ? null : $this->openLink($state['link']);
        $venue = null === $link ? null : $this->venues->find($link->venueNumber);
        if (null === $state || null === $link || null === $venue) {
            return $this->gone('claim.gone.title', 'claim.gone.text');
        }

        $data = new RegisterData();
        if ($identity instanceof PortalIdentity) {
            [$data->firstName, $data->lastName, $data->email] = [$identity->firstName(), $identity->lastName(), $identity->email()];
        }
        $form = $this->createForm(RegisterType::class, $data)->handleRequest($request);
        $page = fn (int $status = Response::HTTP_OK): Response => $this->render('portal/claim/address.html.twig', [
            'form' => $form,
            'venue_name' => $venue->name,
            'correct' => $state['correct'],
        ], new Response(status: $status));
        if (!$form->isSubmitted()) {
            return $page();
        }

        // The honeypot gets the normal answer and no code.
        $honeypot = $form->get('website')->getData();
        if (\is_string($honeypot) && '' !== trim($honeypot)) {
            $this->remember($request->getSession(), $state + ['challenge' => EntityId::generate(), 'email' => (string) $data->email]);

            return $this->redirectToRoute('portal_claim_code', status: Response::HTTP_SEE_OTHER);
        }
        if (!$form->isValid()) {
            return $page(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $this->portalRegisterLimiter->create($this->clientIpKey->of($request))->consume();
        if (!$limit->isAccepted()) {
            $form->addError(new FormError($this->translator->trans('claim.error.rate_limited', [], 'portal')));
            $response = $page(Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        \assert(null !== $data->email && null !== $data->firstName && null !== $data->lastName);
        $challenge = EntityId::generate();
        try {
            $code = $this->commandBus->dispatch(new StartClaim($challenge, $link->venueId, $data->email, $data->firstName, $data->lastName, $link->campaignCode))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            if ([] === $failure->getWrappedExceptions(ClaimThrottled::class)) {
                throw $failure;
            }
            $form->addError(new FormError($this->translator->trans('claim.error.rate_limited', [], 'portal')));

            return $page(Response::HTTP_TOO_MANY_REQUESTS);
        }
        \assert(\is_string($code));
        // After the commit; `PortalMailer` swallows a failed send, and the page offers a new code.
        $this->mailer->sendClaimCode($data->email, $code, $venue->name);
        $this->remember($request->getSession(), $state + ['challenge' => $challenge, 'email' => mb_strtolower($data->email)]);

        return $this->redirectToRoute('portal_claim_code', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/konto/uebernehmen/code', name: 'portal_claim_code', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function code(Request $request): Response
    {
        $session = $request->getSession();
        $state = $this->state($session);
        if (null === $state || !isset($state['challenge'], $state['email'])) {
            return $this->gone('claim.gone.title', 'claim.gone.text');
        }

        $form = $this->createForm(ClaimCodeType::class, new ClaimCodeData())->handleRequest($request);
        $page = fn (int $status = Response::HTTP_OK): Response => $this->render('portal/claim/code.html.twig', [
            'form' => $form,
            'email' => $state['email'],
        ], new Response(status: $status));
        if (!$form->isSubmitted()) {
            return $page();
        }
        if (!$form->isValid()) {
            return $page(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $form->getData();
        \assert($data instanceof ClaimCodeData && null !== $data->code);
        $result = $this->commandBus->dispatch(new VerifyClaim($state['challenge'], $data->code))->last(HandledStamp::class)?->getResult();
        \assert($result instanceof ClaimVerification);

        if (ClaimOutcome::WrongCode === $result->outcome) {
            $form->get('code')->addError(new FormError($this->translator->trans('claim.error.wrong_code', ['left' => $result->attemptsLeft], 'portal')));

            return $page(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (ClaimOutcome::Dead === $result->outcome) {
            $form->get('code')->addError(new FormError($this->translator->trans('claim.error.dead_code', [], 'portal')));

            return $page(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (ClaimOutcome::Deactivated === $result->outcome) {
            $session->remove(self::SESSION);

            return $this->gone('claim.deactivated.title', 'claim.deactivated.text', Response::HTTP_FORBIDDEN);
        }

        \assert(null !== $result->portalUserId && null !== $result->venueNumber && null !== $result->venueName && null !== $result->email);
        $this->announce($result);
        // The proved state gets a fresh session id, so a planted one never reaches the password step.
        $session->migrate(true);
        $verified = [
            'user' => $result->portalUserId,
            'number' => $result->venueNumber,
            'outcome' => $result->outcome->value,
            'correct' => $state['correct'],
            'until' => $this->clock->now()->getTimestamp() + 1800,
        ];
        $this->remember($session, $verified);
        if ($result->needsPassword) {
            return $this->redirectToRoute('portal_claim_password', status: Response::HTTP_SEE_OTHER);
        }

        return $this->finish($session, $verified);
    }

    #[Route('/konto/uebernehmen/passwort', name: 'portal_claim_password', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function password(Request $request): Response
    {
        $session = $request->getSession();
        $state = $this->state($session);
        if (null === $state || !isset($state['user'], $state['outcome'], $state['until']) || $state['until'] < $this->clock->now()->getTimestamp()) {
            return $this->gone('claim.gone.title', 'claim.gone.text');
        }

        $form = $this->createForm(PortalPasswordType::class)->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('portal/claim/password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        $data = $form->getData();
        \assert($data instanceof PortalPasswordData && null !== $data->plainPassword);
        $this->commandBus->dispatch(new ResetPortalPassword($state['user'], $data->plainPassword));

        return $this->finish($session, $state);
    }

    /**
     * Logs the proved account in and sends it on to the photo step; a queued claim waits on the overview.
     *
     * @param array{user: string, number: int, outcome: string, correct: bool} $state
     */
    private function finish(SessionInterface $session, array $state): Response
    {
        $session->remove(self::SESSION);
        $this->login->logIn($state['user']);

        if (ClaimOutcome::Queued->value === $state['outcome']) {
            $this->addFlash('success', PortalFlash::message('claim.flash.queued'));

            return $this->redirectToRoute('portal_home', status: Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', PortalFlash::message('claim.flash.granted'));

        // Both intents pass the photo step, which sends straight on when the import found nothing.
        return $this->redirectToRoute('portal_location_claim_photos', ['number' => $state['number']], Response::HTTP_SEE_OTHER);
    }

    /**
     * After the commit: staff hear of a queued claim, and a claimant who took over an entry that is online already
     * gets the proof of publication at once (an unpublished one gets it when staff accept the review).
     */
    private function announce(ClaimVerification $result): void
    {
        \assert(null !== $result->venueNumber && null !== $result->venueName && null !== $result->email);
        if (ClaimOutcome::Queued === $result->outcome) {
            if (!$result->wroteRequest) {
                return;
            }
            $this->mailer->sendAccessRequestNotice($result->venueName, trim($result->firstName.' '.$result->lastName), $result->email);

            return;
        }
        if (ClaimOutcome::Granted !== $result->outcome) {
            return;
        }
        $venue = $this->venues->find($result->venueNumber);
        if (null !== $venue && $venue->isPublished) {
            $this->mailer->sendLocationAccepted($result->email, (string) $result->firstName, $venue->name, $venue->number, (string) $venue->slug);
        }
    }

    private function openLink(string $id): ?VenuePreviewLink
    {
        $link = $this->previews->find($id);

        return null !== $link && $link->isOpen($this->clock->now()) ? $link : null;
    }

    /**
     * @return array{link?: string, number: int, correct: bool, challenge?: string, email?: string, user?: string, outcome?: string, until?: int}|null
     */
    private function state(SessionInterface $session): ?array
    {
        $state = $session->get(self::SESSION);

        /* @var array{link?: string, number: int, correct: bool, challenge?: string, email?: string, user?: string, outcome?: string, until?: int}|null */
        return \is_array($state) && isset($state['number'], $state['correct']) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function remember(SessionInterface $session, array $state): void
    {
        $session->set(self::SESSION, $state);
    }

    private function gone(string $title, string $text, int $status = Response::HTTP_GONE): Response
    {
        return $this->render('portal/security/notice.html.twig', [
            'title' => $title,
            'text' => $text,
            'link' => 'claim.gone.link',
            'link_route' => 'portal_login',
        ], new Response(status: $status));
    }
}
