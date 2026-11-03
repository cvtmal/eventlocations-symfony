<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * A venue's Statistik tab of #47 (Steps 7.4, 7.5): the record tab, the venue's own figures over a period, and the
 * link from the site statistics.
 */
final class VenueStatsControllerTest extends WebTestCase
{
    use AdminSession {
        setUp as private startSession;
    }
    use ClockSensitiveTrait;
    use Factories;

    private const string NOW = '2026-11-03T14:00:00+01:00';

    protected function setUp(): void
    {
        $this->startSession();
        self::mockTime(self::NOW);
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');
    }

    public function test_the_tab_is_listed_on_the_venue(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertResponseIsSuccessful();
        $tab = $crawler->filter('nav.tabs--record a')->reduce(static fn (Crawler $link): bool => 'Statistik' === $link->text());
        self::assertSame('/admin/locations/'.$venue->number().'/statistik', $tab->attr('href'));
    }

    public function test_the_page_shows_only_that_venues_figures(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Schloss Seeblick']);
        $other = VenueFactory::new()->withoutLocation()->create();
        $number = $venue->number();
        $this->row('2026-11-02', $number, 'detail_view', 7, 4);
        $this->row('2026-11-01', $number, 'detail_view', 5, 3);
        $this->row('2026-11-02', $number, 'impression', 40, 20);
        $this->row('2026-11-02', $number, 'click_website', 2, 2);
        $this->row('2026-11-02', $number, 'click_phone', 1, 1);
        $this->row('2026-11-02', $number, 'merkliste_add', 3, 3);
        $this->row('2026-11-02', $number, 'inquiry', 1, 0);
        // Before the 7 days, and another venue's day.
        $this->row('2026-10-27', $number, 'detail_view', 100, 100);
        $this->row('2026-11-02', $other->number(), 'detail_view', 50, 50);

        $crawler = $this->client->request('GET', '/admin/locations/'.$number.'/statistik?zeitraum=7');

        self::assertResponseIsSuccessful();
        self::assertSame(['12', '7', '40', '3', '3', '1'], $crawler->filter('.stats-kpis .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
        self::assertSame(
            ['Detailaufrufe', 'Besucher der Detailseite', 'Impressionen', 'Kontaktklicks', 'Merkliste', 'Anfragen'],
            $crawler->filter('.stats-kpis .kpi__label')->each(static fn (Crawler $label): string => $label->text()),
        );
        self::assertSame(
            ['Website 2', 'E-Mail 0', 'Telefon 1', 'Karte 0', 'Instagram 0'],
            $crawler->filter('.stats-clicks .property')->each(static fn (Crawler $property): string => $property->filter('.property__label')->text().' '.$property->filter('.property__value')->text()),
        );
        self::assertSame('7 Tage', $crawler->filter('nav.tabs:not(.tabs--record) a[aria-current="page"]')->text());
        self::assertSame('/admin/locations/'.$number.'/statistik?zeitraum=30', $crawler->filter('nav.tabs:not(.tabs--record) a')->eq(1)->attr('href'));
        self::assertSame('Statistik', $crawler->filter('nav.tabs--record a[aria-current="page"]')->text());
        self::assertSame('Noch keine Daten', $crawler->filter('.stats-as-of')->text());
        self::assertSelectorNotExists('#stats-own-traffic');
        self::assertCount(7, $crawler->filter('.bar-chart table tbody tr'));
    }

    public function test_an_unknown_number_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/locations/99999999/statistik');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_site_statistics_link_each_venue_to_its_tab(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => 'Schloss Seeblick']);
        $this->row('2026-11-02', $venue->number(), 'detail_view', 7, 4);

        $crawler = $this->client->request('GET', '/admin/statistik?zeitraum=90');

        self::assertSame('/admin/locations/'.$venue->number().'/statistik?zeitraum=90', $crawler->filter('#stats-venues-table a')->attr('href'));
    }

    private function row(string $day, int $venue, string $metric, int $events, int $visitors): void
    {
        $this->connection->insert('stats_daily', ['day' => $day, 'venue_number' => $venue, 'metric' => $metric, 'events' => $events, 'visitors' => $visitors]);
    }
}
