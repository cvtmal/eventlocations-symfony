<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Shared\Funnel\FunnelEvent;
use App\Shared\Translation\LocalizedRoutes;
use App\Stats\ReadModel\FunnelScorecardQuery;
use App\Stats\ValueObject\FunnelFilter;
use App\Stats\ValueObject\FunnelWindow;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The funnel scorecard (G8, CMO 9.1 and 17) next to the statistics: the steps from a prepared preview to a paid
 * Pro entry, per week and per campaign, from the event log `funnel_events`. `?wochen=4|12|26|52` (default 12) and
 * `?kampagne=<code>|-` (absent is all, `-` is "Ohne Kampagne") are read from `query->all()`, so an array value
 * falls back instead of a 400. Read only: the log is written by whoever emits a step, through `RecordFunnelEvent`.
 */
#[IsGranted('ROLE_EDITOR')]
final class FunnelController extends AbstractController
{
    public function __construct(
        private readonly FunnelScorecardQuery $funnel,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/statistik/funnel', name: 'admin_stats_funnel', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function index(Request $request): Response
    {
        $query = $request->query->all();
        $window = $query['wochen'] ?? null;
        $campaign = $query['kampagne'] ?? null;

        return $this->render('admin/cms/stats/funnel.html.twig', [
            'scorecard' => $this->funnel->scorecard(
                FunnelWindow::fromQuery(\is_string($window) ? $window : null),
                FunnelFilter::fromQuery(\is_string($campaign) ? $campaign : null),
                $this->clock->now(),
            ),
            'windows' => FunnelWindow::cases(),
            'steps' => FunnelEvent::cases(),
        ]);
    }
}
