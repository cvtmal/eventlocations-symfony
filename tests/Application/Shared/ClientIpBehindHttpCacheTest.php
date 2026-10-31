<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Http\ThroughHttpCache;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Research D6: HttpCache rewrites REMOTE_ADDR and forwards the visitor, so what the app sees
 * behind it must still be that visitor: the limiters stay per person, and `Secure` and HSTS
 * still follow HTTPS.
 */
final class ClientIpBehindHttpCacheTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use ThroughHttpCache;

    private const PHONE = '/hochzeitslocation/'.DetailVenues::FULL_SLUG.'/telefon';

    private Connection $connection;
    private EntityManagerInterface $em;
    private int $number;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->number = DetailVenues::full($em)->number();
        $pool = self::getContainer()->get('cache.phone_reveal');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
        $this->startHttpCache($kernel);
    }

    protected function tearDown(): void
    {
        $this->stopHttpCache();
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_the_phone_limit_stays_per_visitor(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame(Response::HTTP_OK, $this->phoneFrom('203.0.113.1')->getStatusCode());
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $this->phoneFrom('203.0.113.1')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->phoneFrom('203.0.113.2')->getStatusCode());
    }

    public function test_https_still_sends_hsts(): void
    {
        $response = $this->through(Request::create('https://localhost/hochzeitslocation/'.DetailVenues::FULL_SLUG));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertTrue($response->headers->has('Strict-Transport-Security'));
    }

    public function test_the_merkliste_cookie_keeps_secure_and_passes_through(): void
    {
        $response = $this->through(Request::create(
            'https://localhost/merkliste/'.$this->number,
            'POST',
            ['_token' => 'csrf-token'],
            [],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        ));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('merkliste', $cookies[0]->getName());
        self::assertTrue($cookies[0]->isSecure());
    }

    private function phoneFrom(string $address): Response
    {
        return $this->through(Request::create(self::PHONE, 'GET', [], [], [], ['REMOTE_ADDR' => $address]));
    }
}
