<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Security\AdminArea;
use App\Admin\Security\AdminIdentity;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TOTP is mandatory (S12), but a new user has no secret yet: the password alone gets them in, and from
 * then on every back-office request leads to the enrolment page until they have confirmed a code.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class RequireTotpEnrolmentListener
{
    private const array EXEMPT_ROUTES = ['admin_totp_enrol', 'admin_login', '_logout_admin'];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly AdminArea $adminArea,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->adminArea->contains($request)) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::EXEMPT_ROUTES, true)) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null === $token || $token instanceof TwoFactorTokenInterface) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof AdminIdentity || $user->isTotpAuthenticationEnabled()) {
            return;
        }

        $event->setResponse($this->adminArea->isJson($request)
            ? new JsonResponse(['errors' => ['' => [$this->translator->trans('error.totp_required', [], 'admin')]]], Response::HTTP_FORBIDDEN)
            : new RedirectResponse($this->urls->generate('admin_totp_enrol')));
    }
}
