<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's tab «Kurzfristig frei» (G14, D55): a Pro manager publishes and ends offers; a past first day is a 422
 * on the field (done-when); a Free manager sees the upsell and «Pro anfragen», and a POST is refused (done-when); an
 * offer a lapsed term hides is marked; another manager's venue stays closed.
 */
final class LocationLastMinuteTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_a_pro_manager_publishes_an_offer(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $this->connection->executeStatement("INSERT INTO venue_terms (venue_id, term_group, term_code, is_main) VALUES (?, 'suitable_for', 'seminar', FALSE)", [$venue->id()]);
        $crawler = $this->client->request('GET', self::url($venue));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Du hast gerade kein Angebot «Kurzfristig frei».');
        $purger = $this->purger();

        $this->client->submit($crawler->filter('form[name="last_minute_offer"]')->form(), [
            'last_minute_offer[startsOn]' => self::day('+3 days'),
            'last_minute_offer[endsOn]' => self::day('+4 days'),
            'last_minute_offer[eventTypes]' => ['seminar'],
            'last_minute_offer[priceText]' => 'Seminarpauschale ab CHF 89',
            'last_minute_offer[minGuests]' => '20',
        ]);

        self::assertResponseRedirects(self::url($venue), 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]));
        self::assertSame([$venue->number()], $purger->venues);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Dein Angebot «Kurzfristig frei» ist online.');
        self::assertStringContainsString('Seminarpauschale ab CHF 89', $crawler->filter('section[aria-labelledby="last-minute-current"]')->text());
    }

    public function test_a_first_day_in_the_past_is_a_422_on_the_field(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $crawler = $this->client->request('GET', self::url($venue));

        $crawler = $this->client->submit($crawler->filter('form[name="last_minute_offer"]')->form(), [
            'last_minute_offer[startsOn]' => self::day('-2 days'),
            'last_minute_offer[priceText]' => 'Ab CHF 50',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Das erste Datum liegt in der Vergangenheit.', $crawler->filter('#last_minute_offer_startsOn')->ancestors()->first()->text());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_free_manager_sees_the_upsell_and_no_form(): void
    {
        $venue = $this->managedVenue(KnownTiers::FREE);

        $crawler = $this->client->request('GET', self::url($venue));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Kurzfristig-frei-Angebote gibt es mit Pro.');
        self::assertCount(0, $crawler->filter('form[name="last_minute_offer"]'));
        self::assertSame('Pro anfragen', $crawler->filter('aside form[action$="/pro-anfragen"] button')->text());
        self::assertSame('portal_location_last_minute', $crawler->filter('aside form[action$="/pro-anfragen"] input[name="back"]')->attr('value'));
        self::assertContains('Kurzfristig frei: nein', $crawler->filter('aside .venue-widget__row')->each(static fn (Crawler $row): string => $row->text()));
    }

    public function test_a_free_venue_cannot_create_one_through_the_form(): void
    {
        $venue = $this->managedVenue(KnownTiers::FREE);
        $this->client->request('GET', self::url($venue));

        $crawler = $this->client->request('POST', self::url($venue), ['last_minute_offer' => [
            'startsOn' => self::day('+3 days'),
            'priceText' => 'Ab CHF 50',
            // The stateless token every /konto form carries; the GET above gives the POST its same-origin Referer.
            '_token' => 'csrf-token',
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mit dem Plan «Gratis» gibt es keine Angebote «Kurzfristig frei».', $crawler->filter('main')->text());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_ending_removes_the_offer(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $offer = LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable(self::day('+3 days')), 'endsOn' => new \DateTimeImmutable(self::day('+3 days'))]);
        $crawler = $this->client->request('GET', self::url($venue));

        $this->client->submit($crawler->filter('form[action="'.self::url($venue).'/'.$offer->id().'/beenden"]')->form());

        self::assertResponseRedirects(self::url($venue), 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_ending_without_the_token_changes_nothing(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        $offer = LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable(self::day('+3 days')), 'endsOn' => new \DateTimeImmutable(self::day('+3 days'))]);

        $this->client->request('POST', self::url($venue).'/'.$offer->id().'/beenden', ['_token' => 'falsch']);

        self::assertResponseRedirects(self::url($venue), 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_offer_of_a_lapsed_term_is_marked_hidden(): void
    {
        $venue = $this->managedVenue(KnownTiers::PRO);
        LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable(self::day('+3 days')), 'endsOn' => new \DateTimeImmutable(self::day('+3 days'))]);
        $this->connection->executeStatement('DELETE FROM subscriptions WHERE venue_id = ?', [$venue->id()]);

        $this->client->request('GET', self::url($venue));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Verborgen: nur mit Pro sichtbar');
    }

    public function test_another_managers_venue_is_closed(): void
    {
        $this->logInAsManager();
        $venue = DetailVenues::minimal(tier: KnownTiers::PRO);

        $this->client->request('GET', self::url($venue));

        self::assertResponseStatusCodeSame(403);
    }

    private function managedVenue(string $tier): Venue
    {
        $venue = DetailVenues::minimal(tier: $tier);
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private static function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/kurzfristig-frei';
    }

    /**
     * A Zurich day relative to today, away from midnight in both directions.
     */
    private static function day(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Zurich')))->modify($modifier)->format('Y-m-d');
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
