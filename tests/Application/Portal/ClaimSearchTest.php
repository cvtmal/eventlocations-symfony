<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\AdminUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class ClaimSearchTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string ASK_BUTTON = 'form[action="/konto/locations/zugriff"] button[type="submit"]';

    public function test_it_finds_a_location_by_name(): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen');
        $this->venue('Gasthof Rössli', 'Bern');

        $crawler = $this->search('seeblick');

        self::assertResponseIsSuccessful();
        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertSame('Meilen', trim($crawler->filter('#claim-results .page-header__title .muted')->text()));
        self::assertSame('Zugriff anfragen', trim($crawler->filter(self::ASK_BUTTON)->text()));
    }

    public function test_name_place_and_action_share_one_cell_so_the_action_stays_reachable_on_a_phone(): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen');

        $crawler = $this->search('seeblick');

        self::assertCount(0, $crawler->filter('#claim-results.table--list'));
        self::assertCount(1, $crawler->filter('#claim-results tbody td'));
        self::assertCount(1, $crawler->filter('#claim-results tbody td '.self::ASK_BUTTON));
    }

    public function test_it_finds_a_location_by_town(): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen');
        $this->venue('Gasthof Rössli', 'Bern');

        self::assertSame(['Gasthof Rössli'], $this->names($this->search('bern')));
    }

    public function test_an_unpublished_location_is_not_listed(): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen', VenueStatus::Unpublished);

        $crawler = $this->search('seeblick');

        self::assertSame([], $this->names($crawler));
        self::assertStringContainsString('Keine Location gefunden', $crawler->filter('#claim-results')->text());
    }

    public function test_a_short_query_searches_nothing(): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen');

        $crawler = $this->search('s');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->names($crawler));
        self::assertStringContainsString('Suchen Sie Ihre Location', $crawler->filter('#claim-results')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenQueries(): iterable
    {
        yield 'an array' => ['/konto/locations/suchen?q[]=seeblick'];
        yield 'a NUL' => ['/konto/locations/suchen?q=see%00blick'];
        yield 'invalid UTF-8' => ['/konto/locations/suchen?q=see%FFblick'];
    }

    #[DataProvider('brokenQueries')]
    public function test_an_array_or_a_nul_query_is_no_error(string $url): void
    {
        $this->logInAsManager();
        $this->venue('Schloss Seeblick', 'Meilen');

        $crawler = $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->names($crawler));
    }

    public function test_a_pending_row_shows_the_pill_and_no_button(): void
    {
        $manager = $this->logInAsManager();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $this->venue('Schloss Seeblick', 'Meilen')]);

        $crawler = $this->search('seeblick');

        self::assertCount(0, $crawler->filter(self::ASK_BUTTON));
        self::assertSame('Anfrage liegt bei uns', trim($crawler->filter('#claim-results .pill')->text()));
    }

    public function test_an_active_row_shows_the_pill_and_no_button(): void
    {
        $manager = $this->logInAsManager();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $this->venue('Schloss Seeblick', 'Meilen')]);

        $crawler = $this->search('seeblick');

        self::assertCount(0, $crawler->filter(self::ASK_BUTTON));
        self::assertSame('Ihre Location', trim($crawler->filter('#claim-results .pill')->text()));
    }

    public function test_the_staff_editors_name_is_not_rendered(): void
    {
        $this->logInAsManager();
        $venue = $this->venue('Schloss Seeblick', 'Meilen');
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'admin_user',
            'actor_id' => AdminUserFactory::createOne(['displayName' => 'Klara Redaktorin'])->id(),
            'command' => 'App\Venues\Command\ChangeVenueBasics',
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{}',
            'created_at' => '2026-10-01 10:00:00+02',
        ]);

        $crawler = $this->search('seeblick');

        self::assertSame(['Schloss Seeblick'], $this->names($crawler));
        self::assertStringNotContainsString('Klara Redaktorin', (string) $this->client->getResponse()->getContent());
    }

    public function test_the_overview_links_to_the_search(): void
    {
        PortalVenueAccessFactory::createOne(['user' => $this->logInAsManager(), 'venue' => VenueFactory::new()->withoutLocation()]);

        $crawler = $this->client->request('GET', '/konto');

        self::assertSame('Location suchen', trim($crawler->filter('.topbar__actions a[href="/konto/locations/suchen"]')->text()));
    }

    private function venue(string $name, string $town, VenueStatus $status = VenueStatus::Published): Venue
    {
        return VenueFactory::createOne(['name' => $name, 'status' => $status, 'town' => DetailVenues::town($town)]);
    }

    private function search(string $q): Crawler
    {
        return $this->client->request('GET', '/konto/locations/suchen?q='.rawurlencode($q));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#claim-results .page-header__title strong')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }
}
