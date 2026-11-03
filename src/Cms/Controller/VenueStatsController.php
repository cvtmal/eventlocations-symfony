<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\LocalizedRoutes;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ReadModel\VenueStatsQuery;
use App\Stats\ValueObject\StatsPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The venue's Statistik tab (#47): its figures over a period, the same partial `/konto` shows its manager.
 */
#[IsGranted('ROLE_EDITOR')]
final class VenueStatsController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueStatsQuery $venueStats,
        private readonly SiteStatsQuery $siteStats,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/locations/{number}/statistik', name: 'admin_locations_stats', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $value = $request->query->all()['zeitraum'] ?? null;
        $period = StatsPeriod::fromQuery(\is_string($value) ? $value : null);
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0);
        $from = $period->firstDay($today);

        return $this->render('admin/cms/venue/stats.html.twig', [
            'venue' => $venue,
            'period' => $period,
            'periods' => StatsPeriod::cases(),
            'as_of' => $this->siteStats->lastIngestAt(),
            'summary' => $this->venueStats->summary($number, $from, $today),
            'daily' => $this->venueStats->dailyDetailViews($number, $from, $today),
        ]);
    }
}
