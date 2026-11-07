<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Factory\VenueFactory;
use App\Factory\VenueSlugHistoryFactory;
use App\Factory\VenueTranslationFactory;
use App\Shared\Security\ContentSecurityPolicy;
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
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * SEC-1 on every kind of response. Debug is off so that the bytes are the production header:
 * with debug on the toolbar rewrites the policy and Symfony lifts it from its exception page.
 */
final class SecurityHeadersTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const string POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; manifest-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";
    /** #52's route-scoped policy, a literal copy so a change to the constant shows up here. */
    private const string MAPS_POLICY = "default-src 'none'; script-src 'self' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com https://*.googleusercontent.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; font-src 'self' https://fonts.gstatic.com; manifest-src 'self'; connect-src 'self' data: blob: https://*.googleapis.com https://*.google.com https://*.gstatic.com; worker-src blob:; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";

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
        $detail = '/eventlokal/'.DetailVenues::FULL_SLUG;

        // [url, status, a text/html response, Accept]
        $rows = [
            ['/eventlokale', 200, true, null],
            [$detail, 200, true, null],
            [$detail.'/telefon', 200, true, null],
            [$detail.'/telefon', 200, false, 'application/json'],
            ['/_search/count', 200, false, null],
            ['/_search/places?q=zu', 200, false, null],
            ['/health', 200, false, null],
            ['/', 200, true, null],
            ['/eventlokal/alter-name', 301, true, null],
            ['/robots.txt', 200, false, null],
            ['/sitemap.xml', 200, false, null],
            ['/eventlokal/gibt-es-nicht', 404, true, null],
            ['/eventlokal/abgemeldet', 410, true, null],
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

        $this->client->request('GET', '/eventlokale');

        $response = $this->client->getResponse();
        self::assertSame(500, $response->getStatusCode());
        $this->assertPlainHeaders($response, '500');
        self::assertSame(self::POLICY, $response->headers->get('Content-Security-Policy'));
    }

    public function test_public_pages_never_carry_a_nonce(): void
    {
        DetailVenues::full($this->em);

        foreach (['/eventlokale', '/eventlokal/'.DetailVenues::FULL_SLUG] as $url) {
            $this->client->request('GET', $url);

            self::assertSame(self::POLICY, $this->client->getResponse()->headers->get('Content-Security-Policy'), $url);
            self::assertStringNotContainsString('nonce=', (string) $this->client->getResponse()->getContent(), $url);
        }
    }

    public function test_the_near_me_page_carries_the_maps_policy(): void
    {
        $this->client->request('GET', '/eventlokal-in-meiner-naehe?ort=8712');
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::MAPS_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(self), payment=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertStringNotContainsString('nonce=', (string) $response->getContent());
    }

    public function test_a_venue_with_a_google_place_carries_the_places_policy(): void
    {
        // #53: the policy is set per response by venue_google_rating(), not by a route default.
        $saved = [$_SERVER['GOOGLE_MAPS_JS_KEY'] ?? null, $_ENV['GOOGLE_MAPS_JS_KEY'] ?? null];
        $_SERVER['GOOGLE_MAPS_JS_KEY'] = $_ENV['GOOGLE_MAPS_JS_KEY'] = 'test-key';
        try {
            $venue = DetailVenues::full($this->em);
            $this->em->flush();
            $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4' WHERE id = ?", [$venue->id()]);

            $this->client->request('GET', '/eventlokal/'.DetailVenues::FULL_SLUG);
            $response = $this->client->getResponse();

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(ContentSecurityPolicy::GOOGLE_PLACES_POLICY, $response->headers->get('Content-Security-Policy'));
            $this->assertPlainHeaders($response, 'detail with a Google place');
            self::assertStringNotContainsString('nonce=', (string) $response->getContent());
        } finally {
            [$_SERVER['GOOGLE_MAPS_JS_KEY'], $_ENV['GOOGLE_MAPS_JS_KEY']] = $saved;
        }
    }

    public function test_only_the_near_me_route_opts_into_another_policy(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $opted = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if ($route->hasDefault(ContentSecurityPolicy::ROUTE_ATTRIBUTE)) {
                $opted[] = $name;
            }
        }

        self::assertSame(['nearby.de'], $opted);
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

        $client->request('GET', '/eventlokal/gibt-es-nicht');

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
