<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Form\LocationForms;
use App\Portal\ReadModel\SubmissionQuery;
use App\Portal\Security\PortalVenueVoter;
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
 * The manager's Statistik tab (#47): the location's figures over a period, the partial staff see on the venue's
 * Statistik tab, plus the note that the manager's own visits count too.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationStatsController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenueStatsQuery $venueStats,
        private readonly SiteStatsQuery $siteStats,
        private readonly ClockInterface $clock,
        private readonly LocationForms $forms,
        private readonly SubmissionQuery $submissions,
    ) {
    }

    #[Route('/konto/locations/{number}/statistik', name: 'portal_location_stats', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $value = $request->query->all()['zeitraum'] ?? null;
        $period = StatsPeriod::fromQuery(\is_string($value) ? $value : null);
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0);
        $from = $period->firstDay($today);

        return $this->render('portal/location/stats.html.twig', [
            'venue' => $venue,
            'submission' => $this->submissions->find($venue->id),
            'period' => $period,
            'periods' => StatsPeriod::cases(),
            'as_of' => $this->siteStats->lastIngestAt(),
            'summary' => $this->venueStats->summary($number, $from, $today),
            'daily' => $this->venueStats->dailyDetailViews($number, $from, $today),
            'offline_form' => $this->forms->offlineForm($number)->createView(),
        ]);
    }
}
