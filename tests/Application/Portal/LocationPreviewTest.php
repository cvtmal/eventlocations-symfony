<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

final class LocationPreviewTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use PortalSession;

    public function test_an_offline_location_previews_and_the_banner_leads_back_to_the_account(): void
    {
        $venue = $this->managedVenue();
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE id = ?", [$venue->id()]);

        $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/vorschau');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($venue->name(), $crawler->filter('main h1')->text());
        self::assertSame('/konto/locations/'.$venue->number(), $crawler->filter('.preview-banner a')->attr('href'));
        self::assertSelectorNotExists('form.inquiry-form__form');
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_a_google_place_shows_the_note_and_loads_nothing_from_google(): void
    {
        $saved = [$_SERVER['GOOGLE_MAPS_JS_KEY'] ?? null, $_ENV['GOOGLE_MAPS_JS_KEY'] ?? null];
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = 'test-key';
        try {
            $venue = $this->managedVenue();
            $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

            $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/vorschau');

            self::assertResponseIsSuccessful();
            self::assertSame('Hier zeigt die Website die Google-Bewertung. Die Vorschau lädt sie nicht.', $crawler->filter('.venue-content > .preview-banner__note')->text());
            self::assertSelectorNotExists('[data-google-rating]');
            // D12, places-photos: no Google photos either, though the key is set in the test env.
            self::assertSelectorNotExists('[data-google-photos]');
            self::assertStringNotContainsString('google-fotos', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('data-maps-key', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('googleapis', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        } finally {
            [$_SERVER['GOOGLE_MAPS_JS_KEY'], $_ENV['GOOGLE_MAPS_JS_KEY']] = $saved;
        }
    }

    public function test_another_managers_location_is_refused(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();

        $this->client->request('GET', '/konto/locations/'.$venue->number().'/vorschau');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function managedVenue(): Venue
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager(), 'venue' => $venue]);

        return $venue;
    }
}
