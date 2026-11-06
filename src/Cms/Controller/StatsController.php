<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ValueObject\CmsFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Twig\SwissFormatExtension;
use App\Stats\Command\IngestStats;
use App\Stats\ReadModel\MonthRow;
use App\Stats\ReadModel\SiteStatsQuery;
use App\Stats\ValueObject\IngestResult;
use App\Stats\ValueObject\StatsPeriod;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The staff statistics (#47): site figures over a period, the "Jetzt aktualisieren" button that runs the
 * nightly ingest on demand, and the media kit with its CSV for prospective clients.
 *
 * Figures read "Stand: <last ingest>", not "bis gestern", because the button can load today's events. The
 * refresh form carries the token `stats_refresh`, checked here: `#[IsCsrfTokenValid]` on HTML sends a
 * logged-in editor to the login page (see `VenueDocumentsController`). A bad token flashes
 * `flash.action_failed` and ingests nothing.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/statistik', options: [LocalizedRoutes::UNLOCALIZED => true])]
final class StatsController extends AbstractController
{
    public const string CSRF_ID = 'stats_refresh';

    private const string ZONE = 'Europe/Zurich';

    // The CSV's shape for Swiss Excel, as `Crm\CsvStream` writes it (Cms may not use Crm).
    private const string CSV_SEPARATOR = ';';
    private const string CSV_EOL = "\r\n";
    private const string CSV_BOM = "\u{FEFF}";
    private const string CSV_CONTENT_TYPE = 'text/csv; charset=UTF-8';

    public function __construct(
        private readonly SiteStatsQuery $stats,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
        private readonly SwissFormatExtension $format,
    ) {
    }

    #[Route('', name: 'admin_stats', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $period = self::period($request);
        $today = $this->today();
        $from = $period->firstDay($today);

        return $this->render('admin/cms/stats/index.html.twig', [
            'period' => $period,
            'periods' => StatsPeriod::cases(),
            'as_of' => $this->stats->lastIngestAt(),
            'summary' => $this->stats->summary($from, $today),
            'daily' => $this->stats->dailyPageViews($from, $today),
            'page_types' => $this->stats->pageTypes($from, $today),
            'venues' => $this->stats->venues($from, $today),
            'csrf_id' => self::CSRF_ID,
        ]);
    }

    #[Route('/aktualisieren', name: 'admin_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        $back = $this->redirectToRoute('admin_stats', ['zeitraum' => self::period($request)->value]);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.action_failed');

            return $back;
        }

        $result = $this->commandBus->dispatch(new IngestStats())->last(HandledStamp::class)?->getResult();
        \assert($result instanceof IngestResult);
        $this->addFlash('success', CmsFlash::message('stats.flash.refreshed', [
            'count' => $result->events,
            'formatted' => $this->format->number($result->events),
        ]));

        return $back;
    }

    #[Route('/mediakit', name: 'admin_stats_media_kit', methods: ['GET'])]
    public function mediaKit(): Response
    {
        $today = $this->today();

        return $this->render('admin/cms/stats/media_kit.html.twig', [
            'as_of' => $this->stats->lastIngestAt(),
            'summary' => $this->stats->summary(StatsPeriod::Days30->firstDay($today), $today),
            'months' => $this->stats->months($today),
        ]);
    }

    /**
     * One row per month, oldest first. The cells are numbers, dates and our own labels, so no formula guard
     * is needed.
     */
    #[Route('/mediakit.csv', name: 'admin_stats_media_kit_csv', methods: ['GET'])]
    public function mediaKitCsv(): Response
    {
        $today = $this->today();
        $lines = [array_map(
            fn (string $column): string => $this->translator->trans('stats.media_kit.column.'.$column, [], 'cms'),
            ['month', 'visits', 'page_views', 'detail_views', 'contact_clicks', 'merkliste_adds', 'inquiries'],
        )];
        foreach ($this->stats->months($today) as $month) {
            $lines[] = self::monthCells($month);
        }

        $csv = self::CSV_BOM;
        foreach ($lines as $cells) {
            $csv .= implode(self::CSV_SEPARATOR, array_map(self::csvCell(...), $cells)).self::CSV_EOL;
        }

        $response = new Response($csv);
        $response->headers->set('Content-Type', self::CSV_CONTENT_TYPE);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'deine-eventlocation-mediakit-'.$today->format('Y-m-d').'.csv',
        ));

        return $response;
    }

    /**
     * @return list<string>
     */
    private static function monthCells(MonthRow $month): array
    {
        return [
            $month->month->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m'),
            (string) $month->visits,
            (string) $month->pageViews,
            (string) $month->detailViews,
            (string) $month->contactClicks,
            (string) $month->merklisteAdds,
            (string) $month->inquiries,
        ];
    }

    /**
     * RFC 4180 quoting for a translated header that might hold the separator or a quote.
     */
    private static function csvCell(string $value): string
    {
        if (!str_contains($value, self::CSV_SEPARATOR) && !str_contains($value, '"') && !str_contains($value, "\n")) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }

    private static function period(Request $request): StatsPeriod
    {
        $value = $request->query->all()['zeitraum'] ?? null;

        return StatsPeriod::fromQuery(\is_string($value) ? $value : null);
    }

    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0);
    }
}
