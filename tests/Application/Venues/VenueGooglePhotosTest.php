<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\WixImageResponses;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * D12, places-photos: Google Places photos on the detail page of a venue without its own, passed through our server.
 * The test env sets GOOGLE_PLACES_PHOTOS_KEY (.env.test); Google's answers come from the test transport and DNS from
 * a stub, so nothing reaches Google. Done-when: no Google value in the cached page, the visitor's browser never
 * contacts Google (every URL is ours), `no-store` on both routes, the uploader's name on each photo, a daily cap
 * that falls back to the placeholder, never on a venue with own photos, never for an unpublished one.
 */
final class VenueGooglePhotosTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string PLACE = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    private const string DETAILS = 'https://places.googleapis.com/v1/places/'.self::PLACE;
    private const string MEDIA = 'https://places.googleapis.com/v1/places/'.self::PLACE.'/photos/REF_one/media?maxHeightPx=480&skipHttpRedirect=true';
    private const string IMAGE = 'https://lh3.googleusercontent.com/place-photos/abc=s480';
    private const string SLUG = DetailVenues::MINIMAL_SLUG;
    private const string FRAGMENT = '/eventlokal/'.self::SLUG.'/google-fotos';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WixImageResponses $google;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
        $pool = self::getContainer()->get('cache.google_places');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $google = self::getContainer()->get(WixImageResponses::class);
        self::assertInstanceOf(WixImageResponses::class, $google);
        $this->google = $google;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function test_the_cached_page_holds_only_our_fragment_url_beside_the_placeholder(): void
    {
        $this->venue();

        $crawler = $this->client->request('GET', '/eventlokal/'.self::SLUG);

        self::assertResponseIsSuccessful();
        $box = $crawler->filter('.venue-placeholder > .photo-placeholder + .venue-google-photos[data-google-photos]');
        self::assertCount(1, $box);
        self::assertSame(self::FRAGMENT, $box->attr('data-google-photos'));
        self::assertSame('', $box->html(), 'the box is empty until the script fills it');
        $html = (string) $this->client->getResponse()->getContent();
        // The place ID is in the rating's link (it may be stored); no photo name, Google host or key is.
        foreach (['REF_', 'googleapis', 'googleusercontent', 'test-places-key'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        self::assertStringContainsString("img-src 'self'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'), 'no Google source in the CSP');
        self::assertSame([], $this->google->requested(), 'rendering the page asks Google nothing');
    }

    public function test_the_fragment_shows_each_photo_with_its_uploader_from_our_own_urls(): void
    {
        $this->venue();
        $this->details();

        $crawler = $this->client->request('GET', self::FRAGMENT);

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame([], $response->headers->getCookies());
        $items = $crawler->filter('.google-photos__item');
        self::assertCount(2, $items, 'the photo without an uploader is left out');
        $img = $items->eq(0)->filter('img');
        self::assertSame(self::FRAGMENT.'/REF_one', $img->attr('src'));
        self::assertSame('Gartenhaus Minimal, Foto 1 von Google Maps', $img->attr('alt'));
        self::assertSame('Foto: Anna Muster (öffnet in neuem Tab) · Google Maps (öffnet in neuem Tab)', $items->eq(0)->filter('figcaption')->text());
        self::assertSame('https://maps.google.com/maps/contrib/1', $items->eq(0)->filter('figcaption a')->eq(0)->attr('href'));
        self::assertSame('https://www.google.com/maps/photo/1', $items->eq(0)->filter('figcaption a')->eq(1)->attr('href'));
        self::assertSame('Foto: Ben · Google Maps', $items->eq(1)->filter('figcaption')->text(), 'no link without an https URL');
        foreach ($crawler->filter('img') as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            self::assertStringStartsWith('/eventlokal/', $node->getAttribute('src'), 'the browser loads every photo from us');
        }
        self::assertSame([self::DETAILS], $this->google->requested());
    }

    public function test_a_photo_is_passed_through_never_stored(): void
    {
        $this->venue();
        $this->google->url(self::MEDIA, new MockResponse((string) json_encode(['photoUri' => self::IMAGE]), ['response_headers' => ['content-type' => 'application/json']]));
        $this->google->url(self::IMAGE, new MockResponse('JPEGBYTES', ['response_headers' => ['content-type' => 'image/jpeg']]));

        $this->client->request('GET', self::FRAGMENT.'/REF_one');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('JPEGBYTES', $response->getContent());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame([self::MEDIA, self::IMAGE], $this->google->requested());
    }

    public function test_over_the_daily_cap_the_placeholder_stays_and_google_is_not_asked(): void
    {
        $this->venue();
        $this->details();
        $limiter = self::getContainer()->get('test.limiter.google_places');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $cap = self::getContainer()->getParameter('google_places.daily_requests');
        self::assertSame(300, $cap, 'the default');
        self::assertTrue($limiter->create('site')->consume($cap)->isAccepted());

        $this->client->request('GET', self::FRAGMENT);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', self::FRAGMENT.'/REF_one');
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->google->requested());
    }

    public function test_a_venue_with_own_public_photos_never_shows_googles(): void
    {
        $this->venue(DetailVenues::full($this->em));

        $crawler = $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-google-photos]'));

        $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG.'/google-fotos');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG.'/google-fotos/REF_one');
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->google->requested());
    }

    public function test_an_unpublished_venue_or_one_without_a_place_gets_nothing(): void
    {
        $venue = $this->venue();
        $this->em->getConnection()->executeStatement('UPDATE venues SET status = :status WHERE id = :id', ['status' => VenueStatus::Unpublished->value, 'id' => $venue->id()]);
        $this->client->request('GET', self::FRAGMENT);
        self::assertResponseStatusCodeSame(204);

        $this->em->getConnection()->executeStatement('UPDATE venues SET status = :status, google_place_id = NULL WHERE id = :id', ['status' => VenueStatus::Published->value, 'id' => $venue->id()]);
        $crawler = $this->client->request('GET', '/eventlokal/'.self::SLUG);
        self::assertCount(0, $crawler->filter('[data-google-photos]'));
        $this->client->request('GET', self::FRAGMENT);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->google->requested());
    }

    private function venue(?Venue $venue = null): Venue
    {
        $venue ??= DetailVenues::minimal();
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE venues SET google_place_id = :place WHERE id = :id', ['place' => self::PLACE, 'id' => $venue->id()]);

        return $venue;
    }

    private function details(): void
    {
        $anna = ['displayName' => 'Anna Muster', 'uri' => 'https://maps.google.com/maps/contrib/1'];
        $this->google->url(self::DETAILS, new MockResponse((string) json_encode(['photos' => [
            ['name' => 'places/'.self::PLACE.'/photos/REF_one', 'widthPx' => 800, 'heightPx' => 600, 'authorAttributions' => [$anna], 'googleMapsUri' => 'https://www.google.com/maps/photo/1'],
            ['name' => 'places/'.self::PLACE.'/photos/REF_nobody', 'widthPx' => 800, 'heightPx' => 600, 'authorAttributions' => []],
            ['name' => 'places/'.self::PLACE.'/photos/REF_two', 'widthPx' => 600, 'heightPx' => 800, 'authorAttributions' => [['displayName' => 'Ben', 'uri' => 'javascript:x']]],
        ]]), ['response_headers' => ['content-type' => 'application/json; charset=utf-8']]));
    }
}
