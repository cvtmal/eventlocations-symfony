<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's Statistik tab of #47 (Step 8.1): their location's figures, the note on their own visits, and the
 * voter that keeps another manager's location closed.
 */
final class LocationStatsControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession {
        setUp as private startPortalSession;
    }

    protected function setUp(): void
    {
        $this->startPortalSession();
        $this->connection->executeStatement('DELETE FROM stats_daily');
        $this->connection->executeStatement('DELETE FROM stats_ingest_files');
    }

    public function test_a_manager_sees_their_figures_and_the_note(): void
    {
        $venue = $this->managedVenue();
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Zurich')))->format('Y-m-d');
        $this->row($today, $venue->number(), 'detail_view', 9, 6);
        $this->row($today, $venue->number(), 'impression', 30, 12);
        $this->row($today, $venue->number(), 'click_email', 2, 2);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame(['9', '6', '30', '2', '0', '0'], $crawler->filter('.stats-kpis .kpi__value')->each(static fn (Crawler $value): string => $value->text()));
        self::assertStringContainsString('Deine eigenen Aufrufe', $crawler->filter('#stats-own-traffic')->text());
        self::assertSame('30 Tage', $crawler->filter('.stats-figures nav.tabs a[aria-current="page"]')->text());
        self::assertSame($this->url($venue).'?zeitraum=7', $crawler->filter('.stats-figures nav.tabs a')->first()->attr('href'));
    }

    public function test_the_tab_is_marked_current(): void
    {
        $venue = $this->managedVenue();

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame(['Angaben', 'Fotos', 'Räume', 'Dokumente', 'Videos', 'Statistik', 'Kurzfristig frei'], $crawler->filter('nav.tabs--record a')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame('Statistik', $crawler->filter('nav.tabs--record .tab--active')->text());
        self::assertCount(1, $crawler->filter('#location-offline form[name="offline"]'));
    }

    public function test_another_managers_location_is_refused(): void
    {
        $this->managedVenue();
        $other = VenueFactory::new()->withoutLocation()->create();

        $this->client->request('GET', '/konto/locations/'.$other->number().'/statistik');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/konto/locations/1/statistik');

        self::assertResponseRedirects('/konto/anmelden');
    }

    private function managedVenue(): Venue
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/statistik';
    }

    private function row(string $day, int $venue, string $metric, int $events, int $visitors): void
    {
        $this->connection->insert('stats_daily', ['day' => $day, 'venue_number' => $venue, 'metric' => $metric, 'events' => $events, 'visitors' => $visitors]);
    }
}
