<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A JSON route an editor may not use, so that AdminAccessDeniedHandler is reached.
 */
#[IsGranted('ROLE_ADMIN')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class FakeAdminOnlyJsonController extends AbstractController
{
    #[Route('/admin/api/probe/nur-admin', name: 'admin_api_probe_admin_only', methods: ['POST'], options: ['unlocalized' => true])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
