<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\Spool;
use App\Stats\StatsPaths;
use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SpoolTest extends TestCase
{
    private string $directory;
    private StatsPaths $paths;
    private Spool $spool;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/stats-spool-'.bin2hex(random_bytes(4));
        $this->paths = new StatsPaths($this->directory);
        $this->spool = new Spool($this->paths);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function test_lines_append_to_the_zurich_days_file(): void
    {
        // 23:30 UTC on 3 November is 00:30 on 4 November in Zurich.
        $this->spool->append([self::view(new \DateTimeImmutable('2026-11-03T23:30:00+00:00'))]);

        self::assertSame(['2026-11-04.ndjson'], $this->files());
        self::assertSame(
            ['{"at":"2026-11-04T00:30:00+01:00","type":"page_view","kind":null,"venues":[46],"page":"detail","path":"/eventlokal/x","ref":"www.google.com","ua":"Mozilla/5.0","vh":"0123456789abcdef0123456789abcdef","bot":false}'],
            $this->lines('2026-11-04.ndjson'),
        );
    }

    public function test_events_of_one_call_on_two_days_go_to_two_files(): void
    {
        $this->spool->append([
            self::view(new \DateTimeImmutable('2026-11-03T22:59:59+00:00')),
            self::view(new \DateTimeImmutable('2026-11-03T23:00:00+00:00')),
        ]);

        self::assertSame(['2026-11-03.ndjson', '2026-11-04.ndjson'], $this->files());
    }

    public function test_after_a_claim_the_writer_starts_a_new_file(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T12:00:00+01:00');
        $this->spool->append([self::view($at)]);
        rename($this->paths->spoolDir().'/2026-11-03.ndjson', $this->paths->spoolDir().'/2026-11-03.0badcafe.claimed');

        $this->spool->append([self::click($at)]);

        self::assertSame(['2026-11-03.0badcafe.claimed', '2026-11-03.ndjson'], $this->files());
        self::assertCount(1, $this->lines('2026-11-03.0badcafe.claimed'));
        self::assertCount(1, $this->lines('2026-11-03.ndjson'));
        self::assertStringContainsString('"type":"click","kind":"phone"', $this->lines('2026-11-03.ndjson')[0]);
    }

    public function test_two_appends_give_whole_lines_that_read_back(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T12:00:00+01:00');
        $this->spool->append([self::view($at), self::click($at)]);
        $this->spool->append([SpoolEvent::inquiry($at, 7)]);

        $lines = $this->lines('2026-11-03.ndjson');
        self::assertCount(3, $lines);
        $events = array_map(static fn (string $line): SpoolEvent => SpoolEvent::fromArray((array) json_decode($line, true, flags: \JSON_THROW_ON_ERROR)), $lines);
        self::assertEquals([self::view($at), self::click($at), SpoolEvent::inquiry($at, 7)], $events);
        self::assertSame('{"at":"2026-11-03T12:00:00+01:00","type":"inquiry","kind":null,"venues":[7],"page":null,"path":null,"ref":null,"ua":null,"vh":null,"bot":false}', $lines[2]);
    }

    private static function view(\DateTimeImmutable $at): SpoolEvent
    {
        return new SpoolEvent($at, EventType::PageView, null, [46], PageType::Detail, '/eventlokal/x', 'www.google.com', 'Mozilla/5.0', '0123456789abcdef0123456789abcdef', false);
    }

    private static function click(\DateTimeImmutable $at): SpoolEvent
    {
        return new SpoolEvent($at, EventType::Click, ClickKind::Phone, [46], PageType::Detail, '/eventlokal/x', null, 'Mozilla/5.0', '0123456789abcdef0123456789abcdef', true);
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

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        $content = (string) file_get_contents($this->paths->spoolDir().'/'.$file);
        self::assertStringEndsWith("\n", $content);

        return explode("\n", rtrim($content, "\n"));
    }
}
