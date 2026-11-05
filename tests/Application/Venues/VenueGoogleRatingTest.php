<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Shared\Security\ContentSecurityPolicy;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The detail page's Google rating (#53) keyless (production until the owner sets the key) and keyed. The key is set
 * before the client boots, as in NearbyPageTest. Debug is off: the CSP bytes are the production header.
 */
final class VenueGoogleRatingTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string KEY = 'GOOGLE_MAPS_JS_KEY';
    private const string PLACE_ID = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    private const string MAPS_URL = 'https://www.google.com/maps/search/?api=1&query=Schloss%20Seeblick&query_place_id=ChIJN1t_tDeuEmsRUsoyG83frY4';
    private const string SITE_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";
    private const string PAGE_CACHE = 'public, s-maxage=86400, stale-if-error=86400, stale-while-revalidate=86400';
    private const string URL = '/hochzeitslocation/'.DetailVenues::FULL_SLUG;

    private ?KernelBrowser $client = null;
    private EntityManagerInterface $em;
    /** @var array{mixed, mixed}|null */
    private ?array $saved = null;

    protected function tearDown(): void
    {
        if (null !== $this->client) {
            $this->em->clear();
            $this->em->getConnection()->rollBack();
            $this->client = null;
        }
        parent::tearDown();

        if (null !== $this->saved) {
            [$_SERVER[self::KEY], $_ENV[self::KEY]] = $this->saved;
            $this->saved = null;
        }
    }

    public function test_keyless_with_a_place_shows_only_the_link(): void
    {
        $this->start('');
        $this->venue(self::PLACE_ID);

        $crawler = $this->page(self::URL);
        $html = (string) $this->client()->getResponse()->getContent();

        $link = $crawler->filter('.venue-content > .venue-google-rating .venue-google-rating__link');
        self::assertCount(1, $link);
        self::assertSame(self::MAPS_URL, $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame('noopener', $link->attr('rel'));
        self::assertSame('Bewertungen auf Google Maps ansehen (öffnet in neuem Tab)', $link->text());
        self::assertCount(0, $crawler->filter('.venue-google-rating--live'));
        foreach (['data-google-rating', 'data-maps-key', 'data-place-id', 'gmp-', 'maps.googleapis.com'] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
        self::assertSame(self::SITE_POLICY, $this->client()->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function test_with_a_key_the_box_waits_for_the_script(): void
    {
        $this->start('test-key-"<&');
        $this->venue(self::PLACE_ID);

        $crawler = $this->page(self::URL);
        $response = $this->client()->getResponse();

        $box = $crawler->filter('.venue-google-rating.venue-google-rating--live[data-google-rating]');
        self::assertCount(1, $box);
        self::assertSame(self::PLACE_ID, $box->attr('data-place-id'));
        self::assertSame('test-key-"<&', $box->attr('data-maps-key'));
        self::assertSame('region', $box->attr('role'));
        self::assertSame('Google-Bewertung', $box->attr('aria-label'));
        self::assertSame(self::MAPS_URL, $box->filter('[data-google-rating-link]')->attr('href'));
        self::assertCount(1, $crawler->filter('script[src*="google-rating"]'));
        self::assertStringNotContainsString('gmp-', (string) $response->getContent());
        self::assertSame(ContentSecurityPolicy::GOOGLE_PLACES_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
        self::assertSame(self::PAGE_CACHE, $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_no_place_renders_nothing_and_keeps_the_site_policy(): void
    {
        $this->start('test-key');
        $this->venue(null);

        $crawler = $this->page(self::URL);

        self::assertCount(0, $crawler->filter('.venue-google-rating'));
        self::assertStringNotContainsString('data-maps-key', (string) $this->client()->getResponse()->getContent());
        self::assertSame(self::SITE_POLICY, $this->client()->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function test_hidden_renders_nothing_and_keeps_the_site_policy(): void
    {
        $this->start('test-key');
        $this->venue(self::PLACE_ID, hidden: true);

        $crawler = $this->page(self::URL);
        $html = (string) $this->client()->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('.venue-google-rating'));
        self::assertStringNotContainsString(self::PLACE_ID, $html);
        self::assertStringNotContainsString('data-maps-key', $html);
        self::assertSame(self::SITE_POLICY, $this->client()->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function test_no_google_value_reaches_json_ld_or_markdown(): void
    {
        $this->start('test-key');
        $this->venue(self::PLACE_ID);

        $crawler = $this->page(self::URL);
        $jsonLd = implode("\n", $crawler->filter('script[type="application/ld+json"]')->each(static fn (Crawler $script): string => $script->text()));
        self::assertNotSame('', $jsonLd);

        $this->client()->request('GET', self::URL.'.md');
        self::assertResponseIsSuccessful();
        $markdown = (string) $this->client()->getResponse()->getContent();

        foreach (['JSON-LD' => $jsonLd, 'Markdown' => $markdown] as $where => $text) {
            self::assertStringNotContainsString(self::PLACE_ID, $text, $where);
            self::assertStringNotContainsString('Google-Bewertung', $text, $where);
        }
    }

    public function test_the_inquiry_re_render_carries_the_places_policy(): void
    {
        $this->start('test-key');
        $this->venue(self::PLACE_ID);
        $limiter = self::getContainer()->get('cache.inquiry_submit');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiter);
        $limiter->clear();

        $crawler = $this->client()->request(
            'POST',
            self::URL.'/anfrage',
            InquiryFormFields::valid(['firstName' => '']),
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-google-rating]'));
        self::assertSame(ContentSecurityPolicy::GOOGLE_PLACES_POLICY, $this->client()->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function venue(?string $placeId, bool $hidden = false): Venue
    {
        $venue = DetailVenues::full($this->em);
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE venues SET google_place_id = :place, google_rating_hidden = :hidden WHERE id = :id',
            ['place' => $placeId, 'hidden' => $hidden ? 'true' : 'false', 'id' => $venue->id()],
        );

        return $venue;
    }

    private function start(string $key): void
    {
        $this->saved = [$_SERVER[self::KEY] ?? null, $_ENV[self::KEY] ?? null];
        $_SERVER[self::KEY] = $_ENV[self::KEY] = $key;

        $this->client = self::createClient(['debug' => false]);
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->em->getConnection()->beginTransaction();
    }

    private function page(string $url): Crawler
    {
        $crawler = $this->client()->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function client(): KernelBrowser
    {
        \assert(null !== $this->client);

        return $this->client;
    }
}
