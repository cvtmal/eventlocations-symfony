<?php

declare(strict_types=1);

namespace App\Tests\Application\Venues;

use App\Factory\VenueSlugHistoryFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The phone reveal (DP-11). Each test sends its own REMOTE_ADDR and the limiter's pool is cleared
 * first, so limits never leak between tests or runs. (An array pool would not do: the client
 * resets services, and with them array pools, between requests.).
 */
final class VenuePhoneControllerTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const URL = '/eventlokal/'.DetailVenues::FULL_SLUG.'/telefon';

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $pool = self::getContainer()->get('cache.phone_reveal');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_html_answer_without_js(): void
    {
        DetailVenues::full($this->em);

        $crawler = $this->get(self::URL, '10.0.0.1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[href="tel:+41441234567"]', '044 123 45 67');
        self::assertSelectorExists('a[href="/eventlokal/'.DetailVenues::FULL_SLUG.'"]');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(1, $crawler->filter('h1'));
        $this->assertPrivateNoindex();
    }

    public function test_json_answer_for_the_script(): void
    {
        DetailVenues::full($this->em);

        $this->get(self::URL, '10.0.0.2', ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            ['phone' => '+41441234567', 'display' => '044 123 45 67'],
            json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
        $this->assertPrivateNoindex();
    }

    public function test_a_liechtenstein_number_keeps_its_country_code(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->changeContact('+4232345678', null, null, null);
        $this->em->flush();

        $this->get(self::URL, '10.0.0.9');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[href="tel:+4232345678"]', '+423 234 56 78');
    }

    public function test_not_found_for_an_unknown_slug_and_a_venue_without_phone(): void
    {
        DetailVenues::minimal();

        $this->get('/eventlokal/gibt-es-nicht/telefon', '10.0.0.3');
        self::assertResponseStatusCodeSame(404);

        $this->get('/eventlokal/'.DetailVenues::MINIMAL_SLUG.'/telefon', '10.0.0.3');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * F12: a plan without contact data hides the phone, and the reveal route answers as it does for a venue that has
     * none, in both shapes.
     */
    public function test_a_venue_on_free_answers_404_even_when_it_holds_a_phone(): void
    {
        $uri = '/hochzeitslocation/'.DetailVenues::OVERFULL_SLUG.'/telefon';
        DetailVenues::overfull(KnownTiers::FREE);

        $this->get($uri, '10.0.0.10');
        self::assertResponseStatusCodeSame(404);

        $this->get($uri, '10.0.0.10', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_same_venue_on_pro_reveals_its_phone(): void
    {
        DetailVenues::overfull(KnownTiers::PRO);

        $this->get('/hochzeitslocation/'.DetailVenues::OVERFULL_SLUG.'/telefon', '10.0.0.11', ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertSame(['phone' => DetailVenues::FULL_PHONE, 'display' => '044 123 45 67'], json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function test_gone_for_an_unpublished_venue(): void
    {
        $venue = DetailVenues::full($this->em);
        $venue->unpublish();
        $this->em->flush();

        $this->get(self::URL, '10.0.0.4');

        self::assertResponseStatusCodeSame(410);
    }

    public function test_an_old_slug_redirects_to_the_current_phone_url(): void
    {
        $venue = DetailVenues::full($this->em);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'altes-schloss']);

        $this->get('/eventlokal/altes-schloss/telefon', '10.0.0.5');

        self::assertResponseRedirects(self::URL, 301);
        $this->assertPrivateNoindex();
    }

    public function test_rate_limited_per_client_ip(): void
    {
        DetailVenues::full($this->em);

        for ($i = 1; $i <= 20; ++$i) {
            $this->get(self::URL, '10.0.0.6', ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseIsSuccessful('request '.$i);
        }

        $this->get(self::URL, '10.0.0.6', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(429);
        $retryAfter = (string) $this->client->getResponse()->headers->get('Retry-After');
        self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $retryAfter);
        $this->assertPrivateNoindex();

        $this->get(self::URL, '10.0.0.7', ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $server
     */
    private function get(string $uri, string $ip, array $server = []): Crawler
    {
        return $this->client->request('GET', $uri, server: ['REMOTE_ADDR' => $ip] + $server);
    }

    private function assertPrivateNoindex(): void
    {
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
        self::assertFalse($response->headers->has('Set-Cookie'));
    }
}
