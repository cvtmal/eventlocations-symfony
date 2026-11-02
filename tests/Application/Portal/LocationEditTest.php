<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Portal\ValueObject\LocationCard;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LocationEditTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_a_manager_with_access_sees_seven_cards_and_no_staff_card(): void
    {
        $venue = $this->managedVenue();

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame($venue->name(), $crawler->filter('.record-header h1')->text());
        self::assertCount(7, $crawler->filter('form.venue-card'));
        foreach (LocationCard::cases() as $card) {
            $form = $crawler->filter('form[name="'.$card->formName().'"]');
            self::assertCount(1, $form, $card->value);
            self::assertSame($card->anchor(), $form->attr('id'), $card->value);
        }
        self::assertSelectorNotExists('form[name="venue_basics"]');
        self::assertSelectorNotExists('form[name="venue_rating"]');
    }

    public function test_the_sidebar_keeps_my_locations_active_inside_a_location(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->managedVenue()));

        self::assertSame('Meine Locations', $crawler->filter('.sidebar__item--active')->text());

        $crawler = $this->client->request('GET', '/konto/profil');

        self::assertSame('Profil', $crawler->filter('.sidebar__item--active')->text());
    }

    public function test_the_page_carries_the_portal_api_token(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->managedVenue()));

        self::assertNotEmpty($crawler->filter('meta[name="csrf-token"]')->attr('content'));
    }

    public function test_the_town_lookup_answers_options(): void
    {
        $this->logInAsManager();

        $this->client->request('GET', '/konto/api/towns?q=meilen');

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($answer);
        self::assertIsArray($answer['options']);
        self::assertSame(['value' => '8706 Meilen', 'label' => '8706 Meilen', 'detail' => 'Zürich'], $answer['options'][0]);
    }

    public function test_a_saved_card_redirects_to_its_anchor(): void
    {
        $venue = $this->managedVenue();

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[name="venue_texts"]')->form(), ['venue_texts[texts][de][summary]' => 'Ein neuer Kurztext.']);

        self::assertResponseRedirects($this->url($venue).'#texte');
        $crawler = $this->client->followRedirect();
        self::assertSame('«Texte» gespeichert.', $crawler->filter('.toast')->text());
    }

    private function managedVenue(): Venue
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }
}
