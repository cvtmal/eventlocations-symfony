<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login form; `form_login` handles the POST. Errors come from the `portal` catalogue, not from
 * Symfony's `security` domain.
 */
final class SecurityController extends AbstractController
{
    #[Route('/konto/anmelden', name: 'portal_login', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('portal_home');
        }

        return $this->render('portal/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error_key' => self::errorKey($authenticationUtils->getLastAuthenticationError()),
        ]);
    }

    private static function errorKey(?AuthenticationException $error): ?string
    {
        return match (true) {
            null === $error => null,
            $error instanceof TooManyLoginAttemptsAuthenticationException => 'login.error.throttled',
            $error instanceof CustomUserMessageAccountStatusException => $error->getMessageKey(),
            default => 'login.error.invalid',
        };
    }
}
