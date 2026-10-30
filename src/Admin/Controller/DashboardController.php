<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Security\AdminIdentity;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_EDITOR')]
final class DashboardController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(#[CurrentUser] AdminIdentity $identity, ClockInterface $clock): Response
    {
        // ICU formats in the process's zone (UTC), so the Zurich wall clock is handed over as UTC.
        $zurich = $clock->now()->setTimezone(new \DateTimeZone(self::ZONE));

        return $this->render('admin/dashboard/index.html.twig', [
            'first_name' => $identity->firstName(),
            'today' => new \DateTimeImmutable($zurich->format('Y-m-d H:i:s'), new \DateTimeZone('UTC')),
        ]);
    }
}
