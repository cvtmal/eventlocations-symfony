<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Http\WixImageResponses;
use App\Tests\Support\Media\MediaFiles;
use App\Venues\WixPhotoCache;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The download through the real wix.client (retries included) over the test transport, so
 * no request leaves the container (#32 D13, Probe 3).
 */
final class WixPhotoCacheTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string ID = 'abc123_0f1e2d3c4b5a~mv2.jpg';
    private const string BYTES = "\xFF\xD8\xFF fiktive Bilddaten";

    private string $cacheDir;
    private WixImageResponses $wix;
    private WixPhotoCache $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cacheDir = \dirname(__DIR__, 3).'/var/test-wix-cache-'.uniqid();
        $wix = self::getContainer()->get(WixImageResponses::class);
        $cache = self::getContainer()->get(WixPhotoCache::class);
        \assert($wix instanceof WixImageResponses && $cache instanceof WixPhotoCache);
        $this->wix = $wix;
        $this->cache = $cache;
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->cacheDir);
        parent::tearDown();
    }

    public function test_a_photo_is_downloaded_once_and_then_read_from_the_cache(): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00');
        $this->wix->image(self::ID, self::BYTES);

        self::assertTrue($this->cache->fetch($this->cacheDir, self::ID));
        self::assertTrue($this->cache->fetch($this->cacheDir, self::ID));

        self::assertSame(['https://static.wixstatic.com/media/'.self::ID], $this->wix->requested());
        self::assertSame(self::BYTES, file_get_contents($this->cache->path($this->cacheDir, self::ID)));
        self::assertTrue($this->cache->has($this->cacheDir, self::ID));
        self::assertSame('2026-10-30 12:00:00.250000', $clock->now()->format('Y-m-d H:i:s.u'), 'one pause, through the clock');
    }

    public function test_a_503_is_retried(): void
    {
        self::mockTime();
        $this->wix->respond(self::ID, new MockResponse('', ['http_code' => 503]), new MockResponse(self::BYTES));

        self::assertTrue($this->cache->fetch($this->cacheDir, self::ID));

        self::assertCount(2, $this->wix->requested());
    }

    public function test_a_permanent_503_gives_up_after_three_retries(): void
    {
        self::mockTime();
        $this->wix->respond(self::ID, new MockResponse('', ['http_code' => 503]));

        self::assertFalse($this->cache->fetch($this->cacheDir, self::ID));

        self::assertCount(4, $this->wix->requested());
        self::assertSame([], $this->files());
    }

    public function test_a_404_is_not_retried_and_leaves_no_file(): void
    {
        self::mockTime();
        $this->wix->respond(self::ID, new MockResponse('nicht gefunden', ['http_code' => 404]));

        self::assertFalse($this->cache->fetch($this->cacheDir, self::ID));

        self::assertCount(1, $this->wix->requested());
        self::assertSame([], $this->files());
    }

    public function test_a_short_body_leaves_neither_the_file_nor_the_part_file(): void
    {
        self::mockTime();
        $this->wix->respond(self::ID, new MockResponse(self::BYTES, ['response_headers' => ['content-length' => (string) (\strlen(self::BYTES) + 100)]]));

        self::assertFalse($this->cache->fetch($this->cacheDir, self::ID));

        self::assertSame([], $this->files());
    }

    public function test_an_empty_body_is_no_photo(): void
    {
        self::mockTime();
        $this->wix->respond(self::ID, new MockResponse(''));

        self::assertFalse($this->cache->fetch($this->cacheDir, self::ID));

        self::assertSame([], $this->files());
    }

    public function test_a_stale_part_file_is_overwritten(): void
    {
        self::mockTime();
        mkdir($this->cacheDir, 0777, true);
        file_put_contents($this->cacheDir.'/'.self::ID.'.part', str_repeat('x', 500));
        $this->wix->image(self::ID, self::BYTES);

        self::assertTrue($this->cache->fetch($this->cacheDir, self::ID));

        self::assertSame([self::ID], $this->files());
        self::assertSame(self::BYTES, file_get_contents($this->cacheDir.'/'.self::ID));
    }

    public function test_a_bad_id_is_refused_before_any_request(): void
    {
        self::mockTime();

        self::assertFalse(WixPhotoCache::isId('../etc/passwd.jpg'));
        self::assertFalse(WixPhotoCache::isId('foto.gif'));
        self::assertTrue(WixPhotoCache::isId('abc_DEF~mv2.JPEG'));

        try {
            $this->cache->fetch($this->cacheDir, '../ausbruch.jpg');
            self::fail('A bad id must be refused.');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $this->wix->requested());
        self::assertDirectoryDoesNotExist($this->cacheDir);
    }

    public function test_an_unregistered_url_fails_the_test_instead_of_reaching_the_network(): void
    {
        self::mockTime();

        $this->expectException(\LogicException::class);

        $this->cache->fetch($this->cacheDir, self::ID);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return is_dir($this->cacheDir) ? array_values(array_filter((array) scandir($this->cacheDir), static fn (string|false $entry): bool => \is_string($entry) && !\in_array($entry, ['.', '..'], true))) : [];
    }
}
