<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Factory\VenueFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * SEC-1 on every kind of response. Debug is off so that the bytes are the production header:
 * with debug on the toolbar rewrites the policy and Symfony lifts it from its exception page.
 */
final class SecurityHeadersTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";

    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient(['debug' => false]);
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
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function test_every_kind_of_response_carries_the_headers(): void
    {
        $venue = DetailVenues::full($this->em);
        VenueSlugHistoryFactory::createOne(['venue' => $venue, 'slug' => 'alter-name']);
        VenueTranslationFactory::createOne([
            'venue' => VenueFactory::createOne(['status' => VenueStatus::Unpublished, 'town' => DetailVenues::town('Bern')]),
            'slug' => 'abgemeldet',
        ]);
        $detail = '/hochzeitslocation/'.DetailVenues::FULL_SLUG;

        // [url, status, a text/html response, Accept]
        $rows = [
            ['/hochzeitslocations', 200, true, null],
            [$detail, 200, true, null],
            [$detail.'/telefon', 200, true, null],
            [$detail.'/telefon', 200, false, 'application/json'],
            ['/_search/count', 200, false, null],
            ['/_search/places?q=zu', 200, false, null],
            ['/health', 200, false, null],
            ['/', 302, true, null],
            ['/hochzeitslocation/alter-name', 301, true, null],
            ['/robots.txt', 200, false, null],
            ['/sitemap.xml', 200, false, null],
            ['/hochzeitslocation/gibt-es-nicht', 404, true, null],
            ['/hochzeitslocation/abgemeldet', 410, true, null],
            ['/admin/login', 200, true, null],
        ];

        foreach ($rows as [$url, $status, $html, $accept]) {
            $this->client->request('GET', $url, server: null === $accept ? [] : ['HTTP_ACCEPT' => $accept]);

            $response = $this->client->getResponse();
            self::assertSame($status, $response->getStatusCode(), $url);
            $this->assertPlainHeaders($response, $url);
            self::assertSame($html ? self::POLICY : null, $response->headers->get('Content-Security-Policy'), $url);
        }
    }

    public function test_a_forced_500_carries_the_headers(): void
    {
        $dispatcher = $this->client->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::CONTROLLER, static function (ControllerEvent $event): void {
            if ($event->isMainRequest()) {
                throw new \RuntimeException('forced');
            }
        });

        $this->client->request('GET', '/hochzeitslocations');

        $response = $this->client->getResponse();
        self::assertSame(500, $response->getStatusCode());
        $this->assertPlainHeaders($response, '500');
        self::assertSame(self::POLICY, $response->headers->get('Content-Security-Policy'));
    }

    public function test_public_pages_never_carry_a_nonce(): void
    {
        DetailVenues::full($this->em);

        foreach (['/hochzeitslocations', '/hochzeitslocation/'.DetailVenues::FULL_SLUG] as $url) {
            $this->client->request('GET', $url);

            self::assertSame(self::POLICY, $this->client->getResponse()->headers->get('Content-Security-Policy'), $url);
            self::assertStringNotContainsString('nonce=', (string) $this->client->getResponse()->getContent(), $url);
        }
    }

    public function test_hsts_only_over_https(): void
    {
        $this->client->request('GET', '/health');
        self::assertFalse($this->client->getResponse()->headers->has('Strict-Transport-Security'));

        $this->client->request('GET', '/health', server: ['HTTPS' => 'on']);
        self::assertSame('max-age=31536000', $this->client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function test_symfonys_debug_exception_page_has_no_csp(): void
    {
        $this->connection->rollBack();
        self::ensureKernelShutdown();
        $client = self::createClient();

        $client->request('GET', '/hochzeitslocation/gibt-es-nicht');

        self::assertResponseStatusCodeSame(404);
        self::assertTrue($client->getResponse()->headers->has('X-Debug-Exception'));
        self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
    }

    private function assertPlainHeaders(Response $response, string $message): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $message);
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'), $message);
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'), $message);
    }
}
