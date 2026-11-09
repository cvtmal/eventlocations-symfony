<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * f16, N1: D48 keeps the Google rating (`GOOGLE_RATING_ENABLED`) and the Google photos (`GOOGLE_PLACES_PHOTOS_KEY`)
 * off by default, so the detail page loads `google-rating.js` only while the rating is live (switch on and a
 * `GOOGLE_MAPS_JS_KEY`, else there is no box to fill) and `google-photos.js` only with the photos key.
 * The variables are set before the client boots, as in VenueGooglePhotosKeylessTest.
 */
final class VenueGoogleScriptsTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    /** @var array<string, array{mixed, mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->saved as $name => [$server, $env]) {
            [$_SERVER[$name], $_ENV[$name]] = [$server, $env];
        }
        $this->saved = [];
    }

    /** @return iterable<string, array{string, string, string, bool, bool}> */
    public static function states(): iterable
    {
        yield 'both off (production default)' => ['0', '', '', false, false];
        yield 'off, with a JS key' => ['0', '', 'test-js-key', false, false];
        yield 'rating on without a JS key (a link only)' => ['1', '', '', false, false];
        yield 'rating live' => ['1', '', 'test-js-key', true, false];
        yield 'photos only' => ['0', 'test-places-key', '', false, true];
        yield 'both on' => ['1', 'test-places-key', 'test-js-key', true, true];
    }

    #[DataProvider('states')]
    public function test_each_google_loader_ships_only_when_it_has_work(string $rating, string $photosKey, string $jsKey, bool $ratingScript, bool $photosScript): void
    {
        foreach (['GOOGLE_RATING_ENABLED' => $rating, 'GOOGLE_PLACES_PHOTOS_KEY' => $photosKey, 'GOOGLE_MAPS_JS_KEY' => $jsKey] as $name => $value) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->beginTransaction();
        try {
            DetailVenues::minimal();
            $em->flush();

            $crawler = $client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
            self::assertResponseIsSuccessful();
            self::assertCount($ratingScript ? 1 : 0, $crawler->filter('script[src*="/js/google-rating"]'), 'google-rating.js');
            self::assertCount($photosScript ? 1 : 0, $crawler->filter('script[src*="/js/google-photos"]'), 'google-photos.js');
            // The other loaders do not depend on either switch.
            self::assertCount(1, $crawler->filter('script[src*="/js/venue-map"]'));
        } finally {
            $em->clear();
            $em->getConnection()->rollBack();
        }
    }
}
