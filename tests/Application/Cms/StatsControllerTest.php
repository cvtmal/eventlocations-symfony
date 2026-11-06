<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Stats\Spool;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Test\Factories;

/**
 * The staff statistics of #47 (Steps 7.2, 7.3, 7.6): the figures over a period, the refresh button that runs
 * the ingest, the media kit and its CSV, the sidebar item and the dashboard widget.
 */
final class StatsControllerTest extends WebTestCase
{
    use AdminSession {
        setUp as private startSession;
        tearDown as private endSession;
    }
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2026-11-03T14:00:00+01:00';
    private const string HASH = '11111111111111111111111111111111';

    private StatsPaths $paths;

    protected function setUp(): void
    {
        $this->startSession();
        self::mockTime(self::NOW);
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->connection->executeStatement('DELETE FROM stats_events');
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');

        $directory = self::getContainer()->getParameter('stats.dir');
        self::assertIsString($directory);
        $this->paths = new StatsPaths($directory);
        $this->cleanSpool();
    }

    protected function tearDown(): void
    {
        $this->cleanSpool();
        $this->endSession();
    }

    public function test_an_editor_sees_the_figures_of_the_last_30_days(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::createOne(['name' => 'Schloss Seeblick']);
        $this->siteRows('2026-11-03', pageViews: 12, visits: 5);
        $this->siteRows('2026-10-05', pageViews: 3, visits: 2);
        // One day before the 30 days.
        $this->siteRows('2026-10-04', pageViews: 100, visits: 100);
        $this->row('2026-11-02', $venue->number(), 'detail_view', 7, 4);
        $this->row('2026-11-02', $venue->number(), 'impression', 40, 20);
        $this->row('2026-11-02', $venue->number(), 'click_website', 2, 2);
        $this->row('2026-11-02', $venue->number(), 'click_phone', 1, 1);
        $this->row('2026-11-02', $venue->number(), 'merkliste_add', 3, 3);
        $this->row('2026-11-02', $venue->number(), 'inquiry', 1, 0);

        $crawler = $this->client->request('GET', '/admin/statistik');

        self::assertResponseIsSuccessful();
        self::assertSame(['7', '15', '7', '3', '3', '1'], self::kpiValues($crawler));
        self::assertSame(['Besuche', 'Seitenaufrufe', 'Detailaufrufe', 'Kontaktklicks', 'Merkliste', 'Anfragen'], $crawler->filter('.stats-kpis .kpi__label')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame('30 Tage', $crawler->filter('nav.tabs a[aria-current="page"]')->text());
        self::assertSame('Noch keine Daten', $crawler->filter('.stats-as-of')->text());
        self::assertCount(30, $crawler->filter('.bar-chart rect'));
        self::assertSame('100', $crawler->filter('.bar-chart rect')->last()->attr('height'));
        self::assertSame(
            ['Schloss Seeblick Nr. '.$venue->number(), '7', '40', '3', '3', '1'],
            $crawler->filter('#stats-venues-table tbody tr')->first()->filter('td')->each(static fn (Crawler $cell): string => $cell->text()),
        );
        self::assertSame(['Startseite', '15', '7'], $crawler->filter('#stats-page-types-table tbody tr')->first()->filter('td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame('Statistik', $crawler->filter('.sidebar__item--active')->text());
    }

    public function test_the_period_narrows_the_figures_and_an_unknown_one_falls_back_to_30_days(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->siteRows('2026-11-03', pageViews: 12, visits: 5);
        $this->siteRows('2026-10-20', pageViews: 3, visits: 2);

        $crawler = $this->client->request('GET', '/admin/statistik?zeitraum=7');
        self::assertSame('12', self::kpiValues($crawler)[1]);
        self::assertSame('7 Tage', $crawler->filter('nav.tabs a[aria-current="page"]')->text());
        self::assertCount(7, $crawler->filter('.bar-chart rect'));
        self::assertSame('/admin/statistik/aktualisieren?zeitraum=7', $crawler->filter('form.stats-refresh')->attr('action'));

        foreach (['/admin/statistik?zeitraum=abc', '/admin/statistik?zeitraum=8', '/admin/statistik?zeitraum[]=7'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertSame('15', self::kpiValues($crawler)[1], $url);
            self::assertSame('30 Tage', $crawler->filter('nav.tabs a[aria-current="page"]')->text(), $url);
        }
    }

    public function test_the_refresh_button_loads_the_spool_and_shows_the_new_figures(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->spoolPageViews(2);
        $crawler = $this->client->request('GET', '/admin/statistik?zeitraum=7');
        self::assertSame('0', self::kpiValues($crawler)[1]);

        $this->client->submit($crawler->filter('form.stats-refresh')->form());

        self::assertResponseRedirects('/admin/statistik?zeitraum=7');
        $crawler = $this->client->followRedirect();
        self::assertSame('Aktualisiert: 2 neue Ereignisse geladen.', $crawler->filter('.toast')->text());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
        self::assertSame(['1', '2'], \array_slice(self::kpiValues($crawler), 0, 2));
        self::assertSame('Stand: 03.11.2026, 14:00', $crawler->filter('.stats-as-of')->text());

        // Nothing new: a second press loads nothing.
        $this->client->submit($crawler->filter('form.stats-refresh')->form());
        self::assertSame('Aktualisiert: keine neuen Ereignisse.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
    }

    public function test_the_refresh_flash_groups_the_count_the_swiss_way(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->spoolPageViews(1234);
        $crawler = $this->client->request('GET', '/admin/statistik');

        $this->client->submit($crawler->filter('form.stats-refresh')->form());

        self::assertSame("Aktualisiert: 1'234 neue Ereignisse geladen.", $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_bad_token_ingests_nothing_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->spoolPageViews(1);

        $this->client->request('POST', '/admin/statistik/aktualisieren?zeitraum=90', ['_token' => 'forged']);

        self::assertResponseRedirects('/admin/statistik?zeitraum=90');
        self::assertSame('Das hat nicht geklappt. Lade die Seite neu und versuche es noch einmal.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM stats_events'));
        self::assertFileExists($this->paths->spoolDir().'/2026-11-03.ndjson');
    }

    public function test_the_media_kit_shows_the_last_30_days_and_12_months(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->siteRows('2026-11-03', pageViews: 12, visits: 5);
        $this->siteRows('2025-12-01', pageViews: 4, visits: 3);

        $crawler = $this->client->request('GET', '/admin/statistik/mediakit');

        self::assertResponseIsSuccessful();
        self::assertSame(['5', '12', '0', '0', '0'], self::kpiValues($crawler));
        $rows = $crawler->filter('#media-kit-months-table tbody tr');
        self::assertCount(12, $rows);
        self::assertSame(['Dezember 2025', '3', '4'], $rows->first()->filter('td')->slice(0, 3)->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame('November 2026', $rows->last()->filter('td')->first()->text());
        self::assertSame('/admin/statistik/mediakit.csv', $crawler->filter('a.btn[href$=".csv"]')->attr('href'));
    }

    public function test_the_media_kit_csv_is_for_swiss_excel(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->siteRows('2026-11-03', pageViews: 12, visits: 5);

        $this->client->request('GET', '/admin/statistik/mediakit.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=deine-eventlocation-mediakit-2026-11-03.csv');
        $body = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}", $body);
        self::assertStringEndsWith("\r\n", $body);
        $lines = explode("\r\n", substr($body, \strlen("\u{FEFF}"), -2));
        self::assertCount(13, $lines);
        self::assertSame('Monat;Besuche;Seitenaufrufe;Detailaufrufe;Kontaktklicks;Merkliste;Anfragen', $lines[0]);
        self::assertSame('2025-12;0;0;0;0;0;0', $lines[1]);
        self::assertSame('2026-11;5;12;0;0;0;0', $lines[12]);
    }

    public function test_the_sidebar_and_the_dashboard_show_the_statistics(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->siteRows('2026-11-03', pageViews: 12, visits: 5);
        // Before the 7 days.
        $this->siteRows('2026-10-27', pageViews: 100, visits: 100);

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $labels = $crawler->filter('.sidebar__label')->each(static fn (Crawler $label): string => $label->text());
        self::assertSame(['Übersicht', 'Statistik'], \array_slice($labels, 0, 2));
        $widget = $crawler->filter('.dashboard__widgets .widget')->first();
        self::assertStringContainsString('widget--wide', (string) $widget->attr('class'));
        self::assertSame('Statistik, letzte 7 Tage', $widget->filter('h2')->text());
        self::assertSame(['5', '12', '0', '0'], $widget->filter('.kpi__value')->each(static fn (Crawler $value): string => $value->text()));
        self::assertSame('/admin/statistik?zeitraum=7', $widget->filter('.widget__head-link a')->attr('href'));
    }

    /**
     * @return list<string>
     */
    private static function kpiValues(Crawler $crawler): array
    {
        return $crawler->filter('.stats-kpis .kpi__value')->each(static fn (Crawler $value): string => $value->text());
    }

    private function siteRows(string $day, int $pageViews, int $visits): void
    {
        $this->row($day, 0, 'page_view', $pageViews, $visits);
        $this->row($day, 0, 'page_view_home', $pageViews, $visits);
    }

    private function row(string $day, int $venue, string $metric, int $events, int $visitors): void
    {
        $this->connection->insert('stats_daily', ['day' => $day, 'venue_number' => $venue, 'metric' => $metric, 'events' => $events, 'visitors' => $visitors]);
    }

    private function spoolPageViews(int $count): void
    {
        $at = new \DateTimeImmutable('2026-11-03T10:00:00', new \DateTimeZone('Europe/Zurich'));
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $events[] = new SpoolEvent($at, EventType::PageView, null, null, PageType::Home, '/', null, 'Mozilla/5.0', self::HASH, false);
        }
        (new Spool($this->paths))->append($events);
    }

    private function cleanSpool(): void
    {
        (new Filesystem())->remove([$this->paths->spoolDir(), $this->paths->saltDir()]);
    }
}
