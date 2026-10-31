<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Support\Cms\HoldsAdvisoryLocks;
use App\Tests\Support\Cms\RecordingCachePool;
use App\Tests\Support\Cms\ResetsContentBlocks;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The snapshot reader of content_blocks (#26). Readers are built by hand on an in-memory pool
 * and a frozen clock, so two instances share a pool the way two requests do; each test runs in
 * a rolled-back transaction, and the lock tests ask a second session.
 */
final class ContentBlocksTest extends KernelTestCase
{
    use HoldsAdvisoryLocks;
    use ResetsContentBlocks;

    private Connection $connection;
    private RecordingCachePool $pool;
    private MockClock $clock;
    private ?bool $lockedAtSave = null;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();
        $this->pool = new RecordingCachePool();
        $this->clock = new MockClock('2026-10-31 12:00:00 UTC');
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->releaseAdvisoryLocks();
        $this->connection->rollBack();
        $this->resetContentBlocks();
        parent::tearDown();
    }

    public function test_a_cold_read_queries_once_and_fills_the_pool(): void
    {
        $this->insert('listing.intro', 'de', 'Willkommen.');
        $holder = $this->holder();

        $holder->reset();
        $first = $this->reader();
        self::assertSame('Willkommen.', $first->text('listing.intro', 'de'));
        self::assertNull($first->text('footer.note', 'de'));
        self::assertSame("<p>Willkommen.</p>\n", $first->html('listing.intro', 'de'));
        self::assertSame(1, $this->rowReads());
        self::assertTrue($this->pool->getItem(ContentBlocks::ITEM)->isHit());

        $holder->reset();
        self::assertSame('Willkommen.', $this->reader()->text('listing.intro', 'de'));
        self::assertCount(0, $holder->getData()['default'] ?? [], 'A second reader is served by the pool.');
    }

    public function test_peek_never_queries_and_misses_on_a_cold_pool(): void
    {
        $this->insert('footer.note', 'de', 'Ein Hinweis.');
        $holder = $this->holder();
        $reader = $this->reader();

        $holder->reset();
        self::assertNull($reader->peek('footer.note', 'de'));
        self::assertNull($reader->peekHtml('footer.note', 'de'));
        self::assertCount(0, $holder->getData()['default'] ?? []);

        $reader->text('footer.note', 'de');
        self::assertSame('<p>Ein Hinweis.</p>', trim((string) $this->reader()->peekHtml('footer.note', 'de')));
    }

    public function test_peek_misses_when_the_pool_fails(): void
    {
        self::assertNull(new ContentBlocks($this->connection, $this->failingPool(), new MarkdownRenderer(), $this->clock)->peek('footer.note', 'de'));
    }

    public function test_html_is_safe_markdown(): void
    {
        $this->insert('listing.intro', 'de', "<script>alert(1)</script>\n\n[x](javascript:alert(1)) und **fett**");

        $html = (string) $this->reader()->html('listing.intro', 'de');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
    }

    public function test_a_top_level_heading_steps_down_so_the_page_keeps_one_h1(): void
    {
        $this->insert('listing.intro', 'de', "# Titel\n\n## Unter\n\nText.");

        $html = (string) $this->reader()->html('listing.intro', 'de');

        self::assertStringContainsString('<h2>Titel</h2>', $html);
        self::assertStringContainsString('<h2>Unter</h2>', $html);
        self::assertStringNotContainsString('<h1', $html);
    }

    public function test_a_code_block_showing_an_h1_stays_escaped(): void
    {
        $this->insert('listing.intro', 'de', "```\n<h1>Titel</h1>\n```");

        self::assertStringContainsString('&lt;h1&gt;Titel&lt;/h1&gt;', (string) $this->reader()->html('listing.intro', 'de'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesWithoutVisibleText(): iterable
    {
        yield 'a lone link reference' => ['[a]: /b'];
        yield 'only NBSP' => ["\u{00A0}"];
        yield 'NBSP paragraphs' => ["\u{00A0}\n\n\u{00A0}"];
    }

    #[DataProvider('bodiesWithoutVisibleText')]
    public function test_a_body_without_visible_text_is_no_block(string $body): void
    {
        $this->insert('listing.intro', 'de', $body);
        $reader = $this->reader();

        self::assertNull($reader->html('listing.intro', 'de'));
        self::assertNull($reader->peekHtml('listing.intro', 'de'));
    }

    public function test_refresh_picks_up_a_new_row(): void
    {
        $reader = $this->reader();
        self::assertNull($reader->text('listing.intro', 'de'));

        $this->insert('listing.intro', 'de', 'Neu.');
        self::assertNull($reader->text('listing.intro', 'de'), 'The snapshot is memoized.');

        $reader->refresh();
        self::assertSame('Neu.', $reader->text('listing.intro', 'de'));
        self::assertSame('Neu.', $this->reader()->peek('listing.intro', 'de'), 'refresh() rebuilt the pool item.');
    }

    public function test_reset_reads_the_pool_again(): void
    {
        $reader = $this->reader();
        self::assertNull($reader->text('listing.intro', 'de'));

        $this->insert('listing.intro', 'de', 'Neu.');
        $this->reader()->refresh();

        $reader->reset();
        self::assertSame('Neu.', $reader->text('listing.intro', 'de'));
    }

    public function test_a_locale_without_a_row_gives_null(): void
    {
        $this->insert('listing.intro', 'de', 'Nur Deutsch.');

        self::assertNull($this->reader()->text('listing.intro', 'fr'));
        self::assertNull($this->reader()->html('listing.intro', 'fr'));
    }

    public function test_the_container_reader_uses_the_content_blocks_pool(): void
    {
        $this->insert('listing.intro', 'de', 'Im Pool.');
        $reader = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $reader);

        $reader->refresh();
        $pool = self::getContainer()->get('cache.content_blocks');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $value = $pool->getItem(ContentBlocks::ITEM)->get();
        self::assertIsArray($value);
        self::assertSame(['de' => ['listing.intro' => 'Im Pool.']], $value['blocks'] ?? null);

        $this->resetContentBlocks();
        self::assertFalse($pool->getItem(ContentBlocks::ITEM)->isHit());
    }

    public function test_a_refresh_stores_under_the_lock(): void
    {
        self::assertFalse($this->advisoryLockIsHeld($this->connection, ContentBlocks::LOCK));

        $this->recordTheLockAtSave();
        $this->reader()->refresh();

        self::assertTrue($this->lockedAtSave);
    }

    public function test_a_cold_read_stores_under_the_lock(): void
    {
        self::assertFalse($this->advisoryLockIsHeld($this->connection, ContentBlocks::LOCK));

        $this->recordTheLockAtSave();
        $this->reader()->text('listing.intro', 'de');

        self::assertTrue($this->lockedAtSave);
    }

    public function test_a_refresh_stores_before_its_commit_releases_the_lock(): void
    {
        $this->outsideTheTestTransaction(function (): void {
            $this->recordTheLockAtSave();
            $this->reader()->refresh();

            self::assertTrue($this->lockedAtSave);
            self::assertFalse($this->advisoryLockIsHeld($this->connection, ContentBlocks::LOCK), 'The commit released it.');
        });
    }

    public function test_a_cold_read_stores_before_its_commit_releases_the_lock(): void
    {
        $this->outsideTheTestTransaction(function (): void {
            $this->recordTheLockAtSave();
            $this->reader()->text('listing.intro', 'de');

            self::assertTrue($this->lockedAtSave);
            self::assertFalse($this->advisoryLockIsHeld($this->connection, ContentBlocks::LOCK), 'The commit released it.');
        });
    }

    public function test_a_cold_read_without_the_lock_serves_the_rows_and_stores_nothing(): void
    {
        $this->insert('listing.intro', 'de', 'Willkommen.');
        $this->holdAdvisoryLock($this->connection, ContentBlocks::LOCK);

        self::assertSame('Willkommen.', $this->reader()->text('listing.intro', 'de'));
        self::assertSame(0, $this->pool->saves);
        self::assertFalse($this->pool->getItem(ContentBlocks::ITEM)->isHit());

        $this->releaseAdvisoryLocks();
        self::assertSame('Willkommen.', $this->reader()->text('listing.intro', 'de'));
        self::assertSame(1, $this->pool->saves, 'The next read stores it.');
    }

    public function test_a_snapshot_younger_than_a_day_is_served_without_a_query(): void
    {
        $this->insert('listing.intro', 'de', 'Alt.');
        $this->reader()->refresh();
        $this->revise('listing.intro', 'Neu.');
        $this->clock->sleep(ContentBlocks::MAX_AGE_SECONDS - 1);
        $saves = $this->pool->saves;
        $holder = $this->holder();

        $holder->reset();
        self::assertSame('Alt.', $this->reader()->text('listing.intro', 'de'));

        self::assertCount(0, $holder->getData()['default'] ?? []);
        self::assertSame($saves, $this->pool->saves);
    }

    public function test_text_rebuilds_a_day_old_snapshot_and_stores_it(): void
    {
        $this->insert('listing.intro', 'de', 'Alt.');
        $this->reader()->refresh();
        $this->revise('listing.intro', 'Neu.');
        $this->clock->sleep(ContentBlocks::MAX_AGE_SECONDS);
        $saves = $this->pool->saves;

        self::assertSame('Neu.', $this->reader()->text('listing.intro', 'de'));
        self::assertSame($saves + 1, $this->pool->saves);

        $holder = $this->holder();
        $holder->reset();
        self::assertSame('Neu.', $this->reader()->text('listing.intro', 'de'));
        self::assertCount(0, $holder->getData()['default'] ?? [], 'The rebuild restarted the snapshot\'s age.');
    }

    public function test_peek_serves_a_day_old_snapshot_without_a_query_and_never_stores(): void
    {
        $this->insert('footer.note', 'de', 'Alt.');
        $this->reader()->refresh();
        $this->revise('footer.note', 'Neu.');
        $this->clock->sleep(30 * ContentBlocks::MAX_AGE_SECONDS);
        $saves = $this->pool->saves;
        $holder = $this->holder();

        $holder->reset();
        self::assertSame('Alt.', $this->reader()->peek('footer.note', 'de'));
        self::assertSame("<p>Alt.</p>\n", $this->reader()->peekHtml('footer.note', 'de'));

        self::assertCount(0, $holder->getData()['default'] ?? []);
        self::assertSame($saves, $this->pool->saves);
    }

    public function test_a_peek_then_text_in_one_request_still_rebuilds(): void
    {
        $this->insert('footer.note', 'de', 'Alt.');
        $this->reader()->refresh();
        $this->revise('footer.note', 'Neu.');
        $this->clock->sleep(ContentBlocks::MAX_AGE_SECONDS);
        $saves = $this->pool->saves;
        $reader = $this->reader();

        self::assertSame("<p>Alt.</p>\n", $reader->peekHtml('footer.note', 'de'));
        self::assertSame('Neu.', $reader->text('footer.note', 'de'));
        self::assertSame($saves + 1, $this->pool->saves);
        self::assertSame("<p>Neu.</p>\n", $reader->html('footer.note', 'de'), 'The rendered text of the old snapshot is gone.');
    }

    public function test_the_old_bare_shape_is_a_miss(): void
    {
        $this->insert('listing.intro', 'de', 'Aus der Tabelle.');
        $this->store(['de' => ['listing.intro' => 'Altes Format.']]);
        $holder = $this->holder();

        $holder->reset();
        self::assertNull($this->reader()->peek('listing.intro', 'de'));
        self::assertCount(0, $holder->getData()['default'] ?? [], 'peek() never queries.');

        self::assertSame('Aus der Tabelle.', $this->reader()->text('listing.intro', 'de'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function corruptItems(): iterable
    {
        yield 'a string' => ['kaputt'];
        yield 'no build time' => [['blocks' => ['de' => ['listing.intro' => 'Kaputt.']]]];
        yield 'no blocks' => [['built_at' => 1793448000]];
        yield 'a build time that is no integer' => [['built_at' => '1793448000', 'blocks' => ['de' => ['listing.intro' => 'Kaputt.']]]];
        yield 'blocks that are no list' => [['built_at' => 1793448000, 'blocks' => 'kaputt']];
        yield 'a locale that is no list' => [['built_at' => 1793448000, 'blocks' => ['de' => 'kaputt']]];
        yield 'a numeric locale' => [['built_at' => 1793448000, 'blocks' => [0 => ['listing.intro' => 'Kaputt.']]]];
        yield 'a body that is no string' => [['built_at' => 1793448000, 'blocks' => ['de' => ['listing.intro' => 1]]]];
    }

    #[DataProvider('corruptItems')]
    public function test_a_corrupt_item_is_a_miss(mixed $value): void
    {
        $this->insert('listing.intro', 'de', 'Aus der Tabelle.');
        $this->store($value);

        self::assertNull($this->reader()->peek('listing.intro', 'de'));
        self::assertSame('Aus der Tabelle.', $this->reader()->text('listing.intro', 'de'));
        self::assertSame('Aus der Tabelle.', $this->reader()->peek('listing.intro', 'de'), 'The read replaced the item.');
    }

    public function test_a_pool_that_fails_on_read_or_save_does_not_fail_the_page(): void
    {
        $this->insert('listing.intro', 'de', 'Aus der Tabelle.');

        $unreadable = new ContentBlocks($this->connection, $this->failingPool(), new MarkdownRenderer(), $this->clock);
        self::assertSame('Aus der Tabelle.', $unreadable->text('listing.intro', 'de'));
        $unreadable->refresh();
        self::assertSame('Aus der Tabelle.', $unreadable->text('listing.intro', 'de'));

        $this->pool->onSave = static function (): void {
            throw new \RuntimeException('cache down');
        };
        self::assertSame('Aus der Tabelle.', $this->reader()->text('listing.intro', 'de'));
        $this->reader()->refresh();
        self::assertFalse($this->pool->getItem(ContentBlocks::ITEM)->isHit());
    }

    /**
     * What a second session sees at the moment of the save. It is only recorded here, because
     * rebuild() swallows whatever a save throws, an assertion's failure too. The test's transaction
     * keeps the lock past the save, so one test proves one path.
     */
    private function recordTheLockAtSave(): void
    {
        $this->pool->onSave = function (): void {
            $this->lockedAtSave = $this->advisoryLockIsHeld($this->connection, ContentBlocks::LOCK);
        };
    }

    /**
     * The test's transaction would keep the lock past rebuild()'s, so a save that moved behind the
     * commit would still see it held. Here rebuild() runs a real BEGIN and COMMIT. It writes only
     * to the in-memory pool, so nothing stays in app_test.
     */
    private function outsideTheTestTransaction(\Closure $test): void
    {
        $this->connection->rollBack();
        try {
            $test();
        } finally {
            $this->connection->beginTransaction();
        }
    }

    private function failingPool(): CacheItemPoolInterface
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('cache down'));

        return $pool;
    }

    private function reader(): ContentBlocks
    {
        return new ContentBlocks($this->connection, $this->pool, new MarkdownRenderer(), $this->clock);
    }

    private function store(mixed $value): void
    {
        $this->pool->save($this->pool->getItem(ContentBlocks::ITEM)->set($value));
        $this->pool->saves = 0;
    }

    private function insert(string $key, string $locale, string $body): void
    {
        $this->connection->executeStatement(
            'INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
            [$key, $locale, $body],
        );
    }

    /** A change no refresh sees, as a restore or a direct SQL change is. */
    private function revise(string $key, string $body): void
    {
        $this->connection->executeStatement('UPDATE content_blocks SET body_markdown = ? WHERE key = ?', [$body, $key]);
    }

    /** The statements that read the rows; a rebuild adds its transaction and the lock around them. */
    private function rowReads(): int
    {
        return \count(array_filter(
            $this->holder()->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM content_blocks'),
        ));
    }

    private function holder(): BacktraceDebugDataHolder
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);

        return $holder;
    }
}
