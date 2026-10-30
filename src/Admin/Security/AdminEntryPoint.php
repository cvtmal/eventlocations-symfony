<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Anonymous requests: pages go to the login form, `/admin/api/…` gets a JSON 401 an island can act on.
 */
final class AdminEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        private readonly AdminArea $adminArea,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->adminArea->isJson($request)) {
            // #[IsCsrfTokenValid] reports a bad token as an authentication failure, but the user is logged in.
            return $authException instanceof InvalidCsrfTokenException
                ? new JsonResponse(['errors' => ['' => [$this->translator->trans('error.forbidden', [], 'admin')]]], Response::HTTP_FORBIDDEN)
                : new JsonResponse(['errors' => ['' => [$this->translator->trans('error.unauthenticated', [], 'admin')]]], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->urls->generate('admin_login'));
    }
}
