<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Venues\Entity\Venue;
use App\Venues\PreviewToken;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * G5's done-when: a preview link opens without login; an expired or revoked link answers 410; no
 * preview is in the sitemap, the listing or a cache. Plus Wave G §4a.4: no stats beacon, `noindex`,
 * no Referer, and the photos the public may see only.
 */
final class VenuePreviewLinkTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ThroughHttpCache;

    private const string NAME = 'Remise Vorschau';
    private const string SLUG = 'remise-vorschau';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        self::mockTime('2026-11-07 10:00:00 Europe/Zurich');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function test_a_link_opens_a_draft_without_login_as_a_private_page(): void
    {
        $venue = self::draft();

        $crawler = $this->client->request('GET', $this->link($venue));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame([], $response->headers->getCookies());
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringContainsString(self::NAME, $crawler->filter('main h1')->text());
        self::assertSame('Noch nicht vom Betreiber bestätigt', $crawler->filter('.preview-banner__label')->text());
        $claim = $crawler->filter('.preview-banner__actions a')->eq(0);
        self::assertSame('Gratis übernehmen', $claim->text());
        self::assertSame('mailto:hallo@deine-eventlocation.ch?subject='.rawurlencode('Eintrag Nr. '.$venue->number().' übernehmen: '.self::NAME), $claim->attr('href'));
        self::assertSame('Angaben korrigieren', $crawler->filter('.preview-banner__actions a')->eq(1)->text());

        $html = (string) $response->getContent();
        self::assertStringNotContainsString('js/stats', $html, 'no stats beacon');
        self::assertStringNotContainsString('data-stats-page', $html);
        self::assertSelectorNotExists('link[rel="canonical"]');
        self::assertSelectorNotExists('script[type="application/ld+json"]');
        self::assertSelectorNotExists('meta[property="og:title"]');
        self::assertSelectorNotExists('form.inquiry-form__form');
        self::assertSelectorExists('.venue-placeholder', 'without a photo, the neutral placeholder');
    }

    public function test_only_photos_with_a_rights_grant_show(): void
    {
        $venue = self::draft();
        $granted = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $this->client->request('GET', $this->link($venue));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($granted->id(), $html);
        self::assertStringNotContainsString($waiting->id(), $html);
    }

    public function test_opening_records_preview_opened_with_the_links_campaign_and_a_head_records_nothing(): void
    {
        $venue = self::draft();
        $link = $this->link($venue, 'brief-2026-11');

        $this->client->request('HEAD', $link);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->funnelRows($venue));

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSame(
            [['event' => 'preview_opened', 'campaign_code' => 'brief-2026-11']],
            $this->connection->fetchAllAssociative('SELECT event, campaign_code FROM funnel_events WHERE venue_id = ?', [$venue->id()]),
        );
    }

    public function test_an_expired_link_answers_410(): void
    {
        $venue = self::draft();
        $link = $this->link($venue);

        self::mockTime('2026-11-07 10:00:00 Europe/Zurich +91 days');
        $crawler = $this->client->request('GET', $link);

        self::assertResponseStatusCodeSame(410);
        self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('Dieser Vorschau-Link gilt nicht mehr', $crawler->filter('h1')->text());
        self::assertStringNotContainsString(self::NAME, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('js/stats', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->funnelRows($venue));
    }

    public function test_a_revoked_link_answers_410(): void
    {
        $venue = self::draft();
        $preview = VenuePreviewFactory::new()->revoked()->create(['venue' => $venue, 'expiresAt' => new \DateTimeImmutable('+90 days')]);

        $this->client->request('GET', '/vorschau/'.$this->tokens()->forId($preview->id()));

        self::assertResponseStatusCodeSame(410);
        self::assertSame(0, $this->funnelRows($venue));
    }

    public function test_a_wrong_token_is_a_404(): void
    {
        $venue = self::draft();
        $token = $this->tokens()->forId(VenuePreviewFactory::createOne(['venue' => $venue])->id());
        $tampered = substr($token, 0, -1).('A' === $token[43] ? 'B' : 'A');

        foreach ([$tampered, $this->tokens()->forId(Uuid::v7()->toRfc4122()), str_repeat('-', 44)] as $wrong) {
            $this->client->request('GET', '/vorschau/'.$wrong);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('no-store, private', $this->client->getResponse()->headers->get('Cache-Control'));
        }
        $this->client->request('GET', '/vorschau/zu-kurz');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->funnelRows($venue));
    }

    public function test_a_near_miss_and_another_method_get_the_private_page_too(): void
    {
        $venue = self::draft();
        $link = $this->link($venue);

        foreach (['GET' => [$link.'.', 404], 'POST' => [$link, 405]] as $method => [$url, $status]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame($status, $method);
            $response = $this->client->getResponse();
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'), $method);
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'), $method);
            self::assertStringNotContainsString('js/stats', (string) $response->getContent(), $method.': no beacon sends the path');
        }
        self::assertSame('GET, HEAD', $this->client->getResponse()->headers->get('Allow'));
        self::assertSame(0, $this->funnelRows($venue));
    }

    public function test_googles_element_never_loads_so_the_address_stays_with_us(): void
    {
        $saved = [$_SERVER['GOOGLE_MAPS_JS_KEY'] ?? null, $_ENV['GOOGLE_MAPS_JS_KEY'] ?? null];
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = 'test-key';
        try {
            $venue = self::draft();
            $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

            $this->client->request('GET', $this->link($venue));

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-google-rating]');
            self::assertStringNotContainsString('data-maps-key', (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('googleapis', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        } finally {
            [$_SERVER['GOOGLE_MAPS_JS_KEY'], $_ENV['GOOGLE_MAPS_JS_KEY']] = $saved;
        }
    }

    public function test_the_shared_cache_never_stores_a_preview_and_a_revoke_takes_effect_at_once(): void
    {
        $venue = self::draft();
        $preview = VenuePreviewFactory::createOne(['venue' => $venue]);
        $url = '/vorschau/'.$this->tokens()->forId($preview->id());
        $this->startHttpCache($this->client->getKernel());
        try {
            foreach ([1, 2] as $round) {
                $response = $this->through(Request::create($url));
                self::assertSame(200, $response->getStatusCode());
                self::assertStringNotContainsString('fresh', (string) $response->headers->get('X-Symfony-Cache'), "round {$round}");
                self::assertStringNotContainsString('store', (string) $response->headers->get('X-Symfony-Cache'), "round {$round}");
            }

            $this->connection->executeStatement('UPDATE venue_previews SET revoked_at = CURRENT_TIMESTAMP WHERE id = ?', [$preview->id()]);

            self::assertSame(410, $this->through(Request::create($url))->getStatusCode());
        } finally {
            $this->stopHttpCache();
        }
    }

    public function test_a_previewed_draft_is_in_no_sitemap_and_no_listing(): void
    {
        $venue = self::draft();
        $this->client->request('GET', $this->link($venue));
        self::assertResponseIsSuccessful();

        $published = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Scheune Online']);
        VenueTranslationFactory::createOne(['venue' => $published, 'slug' => 'scheune-online', 'summary' => 'Eine Scheune.']);

        foreach (['/sitemap.xml', '/sitemap-venues-1.xml', '/hochzeitslocations', '/llms.txt', '/_search/count'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString(self::SLUG, $content, $page);
            self::assertStringNotContainsString('/vorschau/', $content, $page);
        }
        self::assertStringContainsString('scheune-online', (string) $this->client->request('GET', '/sitemap-venues-1.xml')->html(), 'the sitemap lists the published venue');
    }

    private static function draft(): Venue
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create([
            'name' => self::NAME,
            'tier' => Tier::Basic,
            'status' => VenueStatus::Unpublished,
            'capacityMin' => null,
            'capacityMax' => 60,
        ]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => self::SLUG, 'summary' => 'Eine vorbereitete Remise.']);

        return $venue;
    }

    private function link(Venue $venue, string $campaign = 'test'): string
    {
        $preview = VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => $campaign, 'at' => new \DateTimeImmutable('2026-11-07 09:00:00 Europe/Zurich'), 'expiresAt' => new \DateTimeImmutable('2026-11-07 10:00:00 Europe/Zurich +90 days')]);

        return '/vorschau/'.$this->tokens()->forId($preview->id());
    }

    private function tokens(): PreviewToken
    {
        $tokens = self::getContainer()->get(PreviewToken::class);
        self::assertInstanceOf(PreviewToken::class, $tokens);

        return $tokens;
    }

    private function funnelRows(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM funnel_events WHERE venue_id = ?', [$venue->id()]);
    }
}
