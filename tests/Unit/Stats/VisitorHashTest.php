<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\StatsPaths;
use App\Stats\VisitorHash;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;

final class VisitorHashTest extends TestCase
{
    private const IP = '203.0.113.42';
    private const UA = 'Mozilla/5.0 (Macintosh)';

    private string $directory;
    private StatsPaths $paths;
    private VisitorHash $hash;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stats-salt-'.bin2hex(random_bytes(4));
        $this->paths = new StatsPaths($this->directory);
        $this->hash = new VisitorHash($this->paths);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function test_the_same_visitor_on_the_same_day_gets_the_same_hash(): void
    {
        $first = $this->hash->of(self::request(self::IP, self::UA), new \DateTimeImmutable('2026-11-03T08:00:00+01:00'));
        $second = (new VisitorHash($this->paths))->of(self::request(self::IP, self::UA), new \DateTimeImmutable('2026-11-03T23:59:00+01:00'));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        self::assertSame($first, $second);
    }

    public function test_another_day_ip_or_user_agent_gets_another_hash(): void
    {
        $day = new \DateTimeImmutable('2026-11-03T12:00:00+01:00');
        $hash = $this->hash->of(self::request(self::IP, self::UA), $day);

        self::assertNotSame($hash, $this->hash->of(self::request(self::IP, self::UA), $day->modify('+1 day')));
        self::assertNotSame($hash, $this->hash->of(self::request('203.0.113.43', self::UA), $day));
        self::assertNotSame($hash, $this->hash->of(self::request(self::IP, self::UA.' Firefox'), $day));
    }

    public function test_the_day_is_the_zurich_day(): void
    {
        $this->hash->of(self::request(self::IP, self::UA), new \DateTimeImmutable('2026-11-03T23:30:00+00:00'));

        self::assertFileExists($this->paths->saltDir().'/2026-11-04');
    }

    public function test_the_salt_is_32_bytes_and_no_file_holds_the_ip(): void
    {
        $this->hash->of(self::request(self::IP, self::UA), new \DateTimeImmutable('2026-11-03T12:00:00+01:00'));

        self::assertSame(32, filesize($this->paths->saltDir().'/2026-11-03'));
        foreach ((new Finder())->files()->in($this->directory) as $file) {
            self::assertStringNotContainsString(self::IP, $file->getContents());
            self::assertStringNotContainsString(self::IP, $file->getFilename());
        }
    }

    private static function request(string $ip, string $userAgent): Request
    {
        return Request::create('/_stats', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]);
    }
}
