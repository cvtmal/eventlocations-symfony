<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\RoomFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The plan card on a manager's location page (F12): the plan, how much of each limit the location uses, and "Pro anfragen"
 * below Pro. The numbers come from the tier rows, so a staff edit of a limit or of the Pro price shows at once.
 */
final class LocationPlanCardTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_a_free_location_shows_its_plan_its_use_and_the_button(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        foreach ([1, 2, 3] as $position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Dein Plan: Free', $crawler->filter('#location-plan')->text());
        self::assertSame(
            ['Fotos 4 von 6', 'Räume 1 von 1', 'PDFs 0 von 1', 'Telefon und Website: verborgen', 'Anlassarten: bis zu 3'],
            $crawler->filter('section[aria-labelledby="location-plan"] .venue-widget__row')->each(static fn (Crawler $row): string => $row->text()),
        );
        self::assertCount(0, $crawler->filter('section[aria-labelledby="location-plan"] .card__head .pill'));
        self::assertSame('Pro kostet CHF 199 pro Jahr zzgl. MwSt., wir stellen die Rechnung von Hand.', $crawler->filter('section[aria-labelledby="location-plan"] p.muted')->text());
        $form = $crawler->filter('form[action="'.$this->url($venue).'/pro-anfragen"]');
        self::assertCount(1, $form);
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame('Pro anfragen', $form->filter('button[type="submit"]')->text());
        self::assertSame('csrf-token', $form->filter('input[name="_token"]')->attr('value'));
    }

    public function test_the_card_sits_in_the_aside_beside_the_editor_cards(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertCount(1, $crawler->filter('aside.shell__aside #location-plan'));
        self::assertCount(1, $crawler->filter('#location-plan'));
        self::assertCount(7, $crawler->filter('form.venue-card'));
    }

    public function test_a_card_save_that_is_refused_still_shows_the_plan(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $crawler = $this->client->request('GET', $this->url($venue));

        $crawler = $this->client->submit($crawler->filter('form[name="venue_texts"]')->form(), ['venue_texts[texts][de][summary]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dein Plan: Free', $crawler->filter('#location-plan')->text());
    }

    public function test_hidden_rows_count_as_used(): void
    {
        $venue = $this->managedVenue(DetailVenues::overfull(KnownTiers::FREE));

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame(
            ['Fotos 9 von 6', 'Räume 2 von 1', 'PDFs 2 von 1', 'Telefon und Website: verborgen', 'Anlassarten: bis zu 3'],
            $crawler->filter('section[aria-labelledby="location-plan"] .venue-widget__row')->each(static fn (Crawler $row): string => $row->text()),
        );
    }

    public function test_a_pro_location_shows_its_plan_and_no_button(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal(tier: KnownTiers::PRO));

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Dein Plan: Pro', $crawler->filter('#location-plan')->text());
        self::assertSame('Top-Eintrag', $crawler->filter('section[aria-labelledby="location-plan"] .card__head .pill')->text());
        self::assertSame(
            ['Fotos 0 von 60', 'Räume 0 von 20', 'PDFs 0 von 5', 'Telefon und Website: sichtbar', 'Anlassarten: alle'],
            $crawler->filter('section[aria-labelledby="location-plan"] .venue-widget__row')->each(static fn (Crawler $row): string => $row->text()),
        );
        self::assertCount(0, $crawler->filter('form[action$="/pro-anfragen"]'));
        self::assertCount(0, $crawler->filter('section[aria-labelledby="location-plan"] p.muted'));
        self::assertSelectorTextNotContains('main', 'Pro anfragen');
    }

    public function test_the_card_follows_a_staff_edit_of_the_limits_and_the_price(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->connection->executeStatement("UPDATE tiers SET photos_max = 9, documents_max = 3, contact_visible = TRUE, event_types_max = 5 WHERE code = 'free'");
        $this->connection->executeStatement("UPDATE tiers SET price_rappen = 24950 WHERE code = 'pro'");

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame(
            ['Fotos 0 von 9', 'Räume 0 von 1', 'PDFs 0 von 3', 'Telefon und Website: sichtbar', 'Anlassarten: bis zu 5'],
            $crawler->filter('section[aria-labelledby="location-plan"] .venue-widget__row')->each(static fn (Crawler $row): string => $row->text()),
        );
        self::assertSame('Pro kostet CHF 249.50 pro Jahr zzgl. MwSt., wir stellen die Rechnung von Hand.', $crawler->filter('section[aria-labelledby="location-plan"] p.muted')->text());
    }

    public function test_a_renamed_tier_is_named_in_the_heading(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->connection->executeStatement("UPDATE tiers SET name = 'Starter' WHERE code = 'free'");

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame('Dein Plan: Starter', $crawler->filter('#location-plan')->text());
    }

    public function test_without_a_pro_tier_there_is_nothing_to_ask_for(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->connection->executeStatement("DELETE FROM tiers WHERE code = 'pro'");

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Dein Plan: Free', $crawler->filter('#location-plan')->text());
        self::assertCount(0, $crawler->filter('form[action$="/pro-anfragen"]'));
    }

    public function test_the_photos_and_statistics_tabs_carry_no_plan_card(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());

        foreach (['/fotos', '/statistik'] as $tab) {
            $crawler = $this->client->request('GET', $this->url($venue).$tab);

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('#location-plan'), $tab);
        }
    }

    private function managedVenue(Venue $venue): Venue
    {
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }
}
