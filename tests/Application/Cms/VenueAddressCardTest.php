<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The address card (D1–D6, L1, P4): one "PLZ / Ort" field, the canton following the town, typed coordinates
 * or the town centre, the region text of a venue without a canton.
 */
final class VenueAddressCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ImportsTownDataset;

    public function test_zip_and_town_store_town_canton_and_zip(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $this->save($venue, ['town' => '8706 Meilen', 'street' => 'Seestrasse 12', 'lat' => '47,2695', 'lng' => '8.6437']);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#adresse');
        self::assertSame(
            ['Meilen', 'zh', '8706', 'Seestrasse 12', 47.2695, 8.6437, false],
            array_values((array) $this->connection->fetchAssociative('SELECT t.name, v.canton_code, v.zip, v.street, v.lat, v.lng, v.coordinates_from_town FROM venues v JOIN towns t ON t.id = v.town_id WHERE v.id = ?', [$venue->id()])),
        );
        $crawler = $this->client->followRedirect();
        self::assertSame('8706 Meilen', $crawler->filter('#venue_address_town')->attr('value'));
    }

    public function test_the_town_centre_button_fills_the_coordinates(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $crawler = $this->editor($venue);
        $form = $crawler->filter('button[name="venue_address[useTownCentre]"]')->form();
        $form['venue_address[town]'] = '8706 Meilen';

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#adresse');
        $row = $this->connection->fetchAssociative('SELECT v.lat = t.lat AND v.lng = t.lng AS same, v.coordinates_from_town FROM venues v JOIN towns t ON t.id = v.town_id WHERE v.id = ?', [$venue->id()]);
        self::assertSame(['same' => true, 'coordinates_from_town' => true], $row);
        self::assertSame('Ortsmitte von Meilen, auf der Website ohne Karte', $this->client->followRedirect()->filter('#adresse .venue-card__hint')->text());
    }

    public function test_a_town_without_coordinates_asks_for_them(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $crawler = $this->save($venue, ['town' => '8706 Meilen']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_address_lat')->attr('aria-invalid'));
        self::assertStringContainsString('Bitte Breite und Länge eintragen oder «Ortsmitte übernehmen» wählen.', $crawler->filter('#adresse')->text());
        self::assertSame('8706 Meilen', $crawler->filter('#venue_address_town')->attr('value'));
        self::assertNull($this->connection->fetchOne('SELECT town_id FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_an_unknown_town_is_an_error_on_the_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $crawler = $this->save($venue, ['town' => '9999 Nirgendwo', 'lat' => '47.0', 'lng' => '8.0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#venue_address_town')->attr('aria-invalid'));
        self::assertStringContainsString('Diesen Ort kennen wir nicht.', $crawler->filter('#adresse')->text());
    }

    public function test_a_venue_can_know_only_its_canton(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $this->connection->executeStatement('UPDATE venues SET lat = 46.95, lng = 7.45 WHERE id = ?', [$venue->id()]);

        $this->save($venue, ['town' => '', 'lat' => '', 'lng' => '', 'canton' => 'be']);

        self::assertResponseRedirects();
        self::assertSame(
            [null, null, 'be', null],
            array_values((array) $this->connection->fetchAssociative('SELECT town_id, zip, canton_code, lat FROM venues WHERE id = ?', [$venue->id()])),
        );
    }

    public function test_a_region_goes_once_a_canton_is_chosen(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::inRegionOnly($this->em(), 'Basel');
        $crawler = $this->editor($venue);
        self::assertSame('Basel', $crawler->filter('#venue_address_regions_de')->attr('value'));

        $this->save($venue, ['canton' => 'bs']);

        self::assertResponseRedirects();
        self::assertSame(['bs', null], array_values((array) $this->connection->fetchAssociative("SELECT v.canton_code, t.region FROM venues v JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de' WHERE v.id = ?", [$venue->id()])));
        self::assertCount(0, $this->client->followRedirect()->filter('#venue_address_regions_de'));
        $card = $this->client->request('GET', '/eventlokale')->filter('.card')->reduce(static fn (Crawler $card): bool => str_contains($card->text(), 'Remise Nur Region'));
        self::assertStringContainsString('Kanton Basel-Stadt', $card->text());
        self::assertStringNotContainsString('Basel ', str_replace('Basel-Stadt', '', $card->text()));
    }

    public function test_enter_saves_and_never_presses_the_town_centre_button(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $buttons = $this->editor($venue)->filter('form[name="venue_address"] button[type="submit"], form[name="venue_address"] button:not([type])');

        self::assertNull($buttons->first()->attr('name'));
        self::assertSame('-1', $buttons->first()->attr('tabindex'));
        self::assertSame('true', $buttons->first()->attr('aria-hidden'));
        self::assertSame('venue_address[useTownCentre]', $buttons->eq(1)->attr('name'));
    }

    public function test_the_first_published_town_brings_the_place_tab_back(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::inCantonOnly();
        $this->client->request('GET', '/eventlokale');
        self::assertSelectorExists('.region-filter--canton-only');

        $this->save($venue, ['town' => '8706 Meilen', 'lat' => '47.2695', 'lng' => '8.6437', 'canton' => '']);

        self::assertResponseRedirects();
        $this->client->request('GET', '/eventlokale');
        self::assertSelectorNotExists('.region-filter--canton-only');
    }

    private function editor(Venue $venue): Crawler
    {
        return $this->client->request('GET', '/admin/locations/'.$venue->number());
    }

    /**
     * Fills the address card and presses "Speichern".
     *
     * @param array<string, string> $values
     */
    private function save(Venue $venue, array $values): Crawler
    {
        $form = $this->editor($venue)->filter('form[name="venue_address"]')->selectButton('Speichern')->form();
        foreach ($values as $field => $value) {
            $form['venue_address['.$field.']'] = $value;
        }

        return $this->client->submit($form);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
