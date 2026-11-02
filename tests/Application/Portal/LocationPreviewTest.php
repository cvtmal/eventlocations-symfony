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
