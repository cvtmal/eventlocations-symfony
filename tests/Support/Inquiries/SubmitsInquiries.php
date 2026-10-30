<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use App\Inquiries\Event\InquirySubmitted;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The application tests of the inquiry POST: the client shares the test's connection (no
 * reboot), so fixtures are visible to the request and rolled back afterwards. The limiter's pool
 * is cleared per test, and a browser's `Sec-Fetch-Site` header is sent unless a test replaces it.
 */
trait SubmitsInquiries
{
    private KernelBrowser $client;
    private Connection $connection;
    private EntityManagerInterface $em;

    private function start(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $pool = self::getContainer()->get('cache.inquiry_submit');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    private function finish(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
    }

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $server
     */
    private function post(string $slug, array $fields, array $server = []): Crawler
    {
        return $this->client->request('POST', '/hochzeitslocation/'.$slug.'/anfrage', $fields, [], $server + ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM inquiries ORDER BY created_at');
    }

    /**
     * @return object{ids: list<string>}
     */
    private function listen(): object
    {
        $received = new class {
            /** @var list<string> */
            public array $ids = [];
        };
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(InquirySubmitted::class, static function (InquirySubmitted $event) use ($received): void {
            $received->ids[] = $event->inquiryId;
        });

        return $received;
    }

    private function assertPrivate(): void
    {
        $response = $this->client->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertSame([], $response->headers->getCookies());
    }
}
