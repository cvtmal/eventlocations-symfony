<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use App\Shared\Translation\LocalizedRoutes;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe: 200 when the database answers, 503 otherwise.
 */
final class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return self::uncached(new JsonResponse(['status' => 'error'], JsonResponse::HTTP_SERVICE_UNAVAILABLE));
        }

        return self::uncached(new JsonResponse(['status' => 'ok']));
    }

    /**
     * No proxy or monitor may answer the check from a cache (#19).
     */
    private static function uncached(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
