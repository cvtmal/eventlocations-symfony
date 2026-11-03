<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\Spool;
use App\Stats\SpoolReader;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SpoolReaderTest extends TestCase
{
    private const string CLAIMED = '/^\d{4}-\d{2}-\d{2}\.[0-9a-f]{8}\.claimed$/';

    private string $directory;
    private StatsPaths $paths;
    private Spool $spool;
    private SpoolReader $reader;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stats-reader-'.bin2hex(random_bytes(4));
        $this->paths = new StatsPaths($this->directory);
        $this->spool = new Spool($this->paths);
        $this->reader = new SpoolReader($this->paths);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function test_a_claim_renames_todays_and_older_files(): void
    {
        $this->spool->append([
            self::view(new \DateTimeImmutable('2026-11-01T10:00:00+01:00')),
            self::view(new \DateTimeImmutable('2026-11-03T10:00:00+01:00')),
        ]);
        // Not a day file: a claim leaves it alone.
        file_put_contents($this->paths->spoolDir().'/notes.txt', 'x');

        $this->reader->claimAll();

        $claimed = $this->reader->claimed();
        self::assertCount(2, $claimed);
        self::assertMatchesRegularExpression(self::CLAIMED, $claimed[0]);
        self::assertStringStartsWith('2026-11-01.', $claimed[0]);
        self::assertStringStartsWith('2026-11-03.', $claimed[1]);
        self::assertSame(array_merge($claimed, ['notes.txt']), $this->files());
    }

    public function test_a_second_claim_without_a_new_file_renames_nothing(): void
    {
        $this->spool->append([self::view(new \DateTimeImmutable('2026-11-03T10:00:00+01:00'))]);
        $this->reader->claimAll();
        $first = $this->files();

        $this->reader->claimAll();

        self::assertSame($first, $this->files());
        self::assertSame($first, $this->reader->unloaded([]));
        self::assertSame([], $this->reader->unloaded($first));
    }

    public function test_a_line_that_does_not_parse_is_skipped_and_counted(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T10:00:00+01:00');
        $this->spool->append([self::view($at)]);
        $file = $this->paths->spoolDir().'/2026-11-03.ndjson';
        file_put_contents($file, implode("\n", [
            'not json',
            '"a string"',
            '{"at":"2026-11-03T10:00:00+01:00","type":"teleport","kind":null,"venues":null,"page":null,"path":null,"ref":null,"ua":null,"vh":null,"bot":false}',
            '{"at":"2026-11-03T10:00:00+01:00","type":"click","kind":"fax","venues":[3],"page":"detail","path":null,"ref":null,"ua":null,"vh":null,"bot":false}',
            '{"at":"2026-11-03T10:00:00+01:00","type":"page_view","kind":null,"venues":[0],"page":"detail","path":null,"ref":null,"ua":null,"vh":null,"bot":false}',
            '{"at":"2026-11-03T10:00:00+01:00","type":"page_v',
            '',
        ])."\n", \FILE_APPEND);
        $this->spool->append([SpoolEvent::inquiry($at, 7)]);
        $this->reader->claimAll();

        $lines = $this->reader->read($this->reader->claimed()[0]);
        $events = iterator_to_array($lines, false);

        self::assertEquals([self::view($at), SpoolEvent::inquiry($at, 7)], $events);
        self::assertSame(6, $lines->getReturn());
    }

    public function test_a_writer_after_the_claim_lands_in_a_new_day_file(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T10:00:00+01:00');
        $this->spool->append([self::view($at)]);
        $this->reader->claimAll();
        [$claimed] = $this->reader->claimed();

        $this->spool->append([SpoolEvent::inquiry($at, 7)]);

        self::assertSame([$claimed, '2026-11-03.ndjson'], $this->files());
        self::assertEquals([self::view($at)], iterator_to_array($this->reader->read($claimed), false));
        $this->reader->claimAll();
        self::assertCount(2, $this->reader->claimed());
        self::assertCount(1, $this->reader->unloaded([$claimed]));
    }

    public function test_deleting_a_claimed_file_twice_is_fine(): void
    {
        $this->spool->append([self::view(new \DateTimeImmutable('2026-11-03T10:00:00+01:00'))]);
        $this->reader->claimAll();
        [$claimed] = $this->reader->claimed();

        $this->reader->delete($claimed);
        $this->reader->delete($claimed);

        self::assertSame([], $this->files());
    }

    public function test_a_name_outside_the_spool_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        iterator_to_array($this->reader->read('../salt/2026-11-03'));
    }

    private static function view(\DateTimeImmutable $at): SpoolEvent
    {
        return new SpoolEvent($at, EventType::PageView, null, [46], PageType::Detail, '/hochzeitslocation/x', 'www.google.com', 'Mozilla/5.0', '0123456789abcdef0123456789abcdef', false);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = array_values(array_diff((array) scandir($this->paths->spoolDir()), ['.', '..']));
        sort($files);

        return array_map('strval', $files);
    }
}
