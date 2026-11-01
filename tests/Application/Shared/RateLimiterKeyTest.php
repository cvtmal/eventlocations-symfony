<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Shared\Security\ClientIpKey;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SubmitsInquiries;
use App\Tests\Support\Venues\DetailVenues;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterStateInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * #20: FlockStore names a file after the limiter's id and never deletes it, and the cached state
 * holds that id too, so a raw key stays on disk. Each case consumes through the real route and then
 * reads the real var/lock. The lock names are what FlockStore makes of an address: it keeps digits
 * and dots, and turns a colon into a dash. The limit itself and the separation of two addresses are
 * pinned by VenuePhoneControllerTest, SubmitInquiryControllerTest and the two cases at the end.
 */
final class RateLimiterKeyTest extends WebTestCase
{
    use Factories;
    use ImportsTownDataset;
    use SubmitsInquiries;

    private const PHONE_LIMIT = 20;
    private const INQUIRY_LIMIT = 5;

    protected function setUp(): void
    {
        $this->start();
        $pool = self::getContainer()->get('cache.phone_reveal');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    protected function tearDown(): void
    {
        $this->finish();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['198.51.100.23', '198.51.100.23'];
        yield 'IPv6' => ['2001:db8:51::23', '2001-db8-51-23'];
    }

    #[DataProvider('addresses')]
    public function test_a_phone_reveal_leaves_no_address_in_a_lock_file_name(string $ip, string $lockName): void
    {
        $this->reveal($ip);

        self::assertResponseStatusCodeSame(404);
        $this->assertOnlyTheKeyIsInALockFileName('phone_reveal', $ip, $lockName);
    }

    #[DataProvider('addresses')]
    public function test_an_inquiry_leaves_no_address_in_a_lock_file_name(string $ip, string $lockName): void
    {
        DetailVenues::full($this->em);
        $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(), ['REMOTE_ADDR' => $ip]);

        self::assertResponseStatusCodeSame(303);
        $this->assertOnlyTheKeyIsInALockFileName('inquiry_submit', $ip, $lockName);
    }

    public function test_the_cached_counter_does_not_hold_the_address_either(): void
    {
        $ip = '198.51.100.24';
        $this->reveal($ip);

        $pool = self::getContainer()->get('cache.phone_reveal');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $state = $pool->getItem(sha1('phone_reveal-'.self::keyOf($ip)))->get();

        self::assertInstanceOf(LimiterStateInterface::class, $state);
        self::assertStringNotContainsString($ip, serialize($state));
    }

    public function test_the_phone_limit_still_counts_one_address_and_not_the_next(): void
    {
        for ($i = 1; $i <= self::PHONE_LIMIT; ++$i) {
            $this->reveal('198.51.100.25');
            self::assertResponseStatusCodeSame(404);
        }

        $this->reveal('198.51.100.25');
        self::assertResponseStatusCodeSame(429);

        $this->reveal('198.51.100.26');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_inquiry_limit_still_counts_one_address_and_not_the_next(): void
    {
        DetailVenues::full($this->em);
        for ($i = 1; $i <= self::INQUIRY_LIMIT; ++$i) {
            $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['message' => 'Anfrage '.$i]), ['REMOTE_ADDR' => '198.51.100.27']);
            self::assertResponseStatusCodeSame(303);
        }

        $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['message' => 'Anfrage 6']), ['REMOTE_ADDR' => '198.51.100.27']);
        self::assertResponseStatusCodeSame(429);

        $this->post(DetailVenues::FULL_SLUG, InquiryFormFields::valid(['message' => 'Anfrage 7']), ['REMOTE_ADDR' => '198.51.100.28']);
        self::assertResponseStatusCodeSame(303);
    }

    private function reveal(string $ip): void
    {
        $this->client->request('GET', '/hochzeitslocation/keine-solche-location/telefon', server: ['REMOTE_ADDR' => $ip]);
    }

    /**
     * The file outlives the run, so a fresh one cannot be asked for: the hashed name proves the
     * lock was taken, whichever run took it first.
     */
    private function assertOnlyTheKeyIsInALockFileName(string $limiter, string $ip, string $lockName): void
    {
        $files = self::lockFiles();
        $hashed = 'sf.'.substr($limiter.'-'.self::keyOf($ip), 0, 50).'.';

        self::assertNotSame([], array_filter($files, static fn (string $file): bool => str_starts_with($file, $hashed)), 'Nothing was locked, so this test proves nothing.');
        self::assertSame([], array_filter($files, static fn (string $file): bool => str_contains($file, $lockName)));
    }

    private static function keyOf(string $ip): string
    {
        $key = self::getContainer()->get(ClientIpKey::class);
        self::assertInstanceOf(ClientIpKey::class, $key);

        return $key->of(Request::create('/', server: ['REMOTE_ADDR' => $ip]));
    }

    /**
     * @return list<string>
     */
    private static function lockFiles(): array
    {
        $files = scandir(\dirname(__DIR__, 3).'/var/lock');
        self::assertIsArray($files);

        return array_values(array_diff($files, ['.', '..']));
    }
}
