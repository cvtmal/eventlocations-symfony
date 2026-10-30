<?php

declare(strict_types=1);

namespace App\Admin\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * `/admin/api/…` gets a JSON 403; everything else keeps the default 403 page (null).
 */
final class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AdminArea $adminArea,
    ) {
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if (!$this->adminArea->isJson($request)) {
            return null;
        }

        return new JsonResponse(['errors' => ['' => [$this->translator->trans('error.forbidden', [], 'admin')]]], Response::HTTP_FORBIDDEN);
    }
}
