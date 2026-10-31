<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\Command\RefreshContentBlockSnapshot;
use App\Cms\Command\SaveLandingTexts;
use App\Cms\Command\SaveTextBlock;
use App\Cms\CommandHandler\RefreshContentBlockSnapshotHandler;
use App\Cms\Exception\ContentTooLong;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Exception\UnknownContentBlock;
use App\Cms\Exception\UnknownLandingPage;
use App\Cms\Repository\ContentBlockRepository;
use App\Cms\ValueObject\ContentVersion;
use App\Geo\ValueObject\Canton;
use App\Shared\ReadModel\ContentBlocks;
use App\Tests\Support\Cms\HoldsAdvisoryLocks;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Search\LandingFixtures;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Cms write side of #26 through command.bus against app_test in a rolled-back
 * transaction: rows added, revised and removed, the version check (P8) and the subject's lock,
 * the audit row, the listings purge and the snapshot refresh after the commit (P4), failed or not.
 * Lock tests wait with a short lock_timeout for a lock a second session holds, so what the lock
 * guards fails for real.
 */
final class SaveContentBlocksTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use HoldsAdvisoryLocks;
    use LandingFixtures;
    use ResetsContentBlocks;

    private const array LANDING_KEYS = ['landing.canton.zh.intro', 'landing.canton.zh.title', 'landing.canton.zh.description'];

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->resetContentBlocks();
        $this->inCanton(Canton::ZH, 1);
    }

    protected function tearDown(): void
    {
        $this->releaseAdvisoryLocks();
        $this->em->clear();
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->resetContentBlocks();
        parent::tearDown();
    }

    public function test_a_save_adds_revises_and_removes_rows(): void
    {
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => "  Hochzeiten am **See**.\n", 'title' => 'Zürich feiert', 'description' => null]], $this->version(self::LANDING_KEYS), ['intro', 'title'], ['de']));
        self::assertSame(['landing.canton.zh.intro' => 'Hochzeiten am **See**.', 'landing.canton.zh.title' => 'Zürich feiert'], $this->bodies(self::LANDING_KEYS));

        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Hochzeiten am **See**.', 'title' => 'Zürich heiratet', 'description' => 'Am See.']], $this->version(self::LANDING_KEYS), ['title', 'description'], ['de']));
        self::assertSame(['landing.canton.zh.description' => 'Am See.', 'landing.canton.zh.intro' => 'Hochzeiten am **See**.', 'landing.canton.zh.title' => 'Zürich heiratet'], $this->bodies(self::LANDING_KEYS));

        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => null, 'title' => "  \n", 'description' => '']], $this->version(self::LANDING_KEYS), ['intro', 'title', 'description'], ['de']));
        self::assertSame([], $this->bodies(self::LANDING_KEYS));
    }

    public function test_a_key_left_out_of_the_texts_stays_as_it_is(): void
    {
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Am See.', 'title' => 'Zürich feiert']], $this->version(self::LANDING_KEYS), ['intro', 'title'], ['de']));
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['title' => null]], $this->version(self::LANDING_KEYS), ['title'], ['de']));

        self::assertSame(['landing.canton.zh.intro' => 'Am See.'], $this->bodies(self::LANDING_KEYS));
    }

    public function test_the_audit_row_names_the_subject_locales_and_fields_only(): void
    {
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Ein geheimer Text', 'title' => 'Zürich feiert', 'description' => null]], $this->version(self::LANDING_KEYS), ['intro', 'title'], ['de']));
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Ein geheimer Hinweis'], $this->version(['footer.note']), ['de']));

        self::assertSame([
            ['command' => SaveLandingTexts::class, 'subject_type' => 'landing_page', 'subject_id' => 'canton.zh', 'changes' => ['fields' => ['intro', 'title'], 'locales' => ['de']]],
            ['command' => SaveTextBlock::class, 'subject_type' => 'content_block', 'subject_id' => 'footer.note', 'changes' => ['fields' => ['body'], 'locales' => ['de']]],
        ], $this->auditRows());
        self::assertStringNotContainsString('geheim', (string) $this->connection->fetchOne('SELECT string_agg(changes::text, \' \') FROM admin_audit_log'));
    }

    public function test_each_save_that_changes_a_row_purges_the_listings_once_and_refreshes_the_snapshot(): void
    {
        $purges = $this->purger()->listings;
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Bitte beachten.'], $this->version(['footer.note']), ['de']));

        self::assertSame($purges + 1, $this->purger()->listings);
        self::assertSame('Bitte beachten.', $this->contentBlocks()->peek('footer.note', 'de'));
        self::assertSame(['de' => ['footer.note' => 'Bitte beachten.']], $this->storedBlocks(), 'The pool holds the rebuilt snapshot, not a deleted item (P4).');

        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Am See.']], $this->version(self::LANDING_KEYS), ['intro'], ['de']));
        self::assertSame($purges + 2, $this->purger()->listings);
        self::assertSame('Am See.', $this->contentBlocks()->text('landing.canton.zh.intro', 'de'));

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => null], $this->version(['footer.note']), ['de']));
        self::assertSame($purges + 3, $this->purger()->listings);
        self::assertNull($this->contentBlocks()->peek('footer.note', 'de'));
    }

    public function test_the_snapshot_is_rebuilt_before_the_listings_are_purged(): void
    {
        $atPurge = null;
        $this->purger()->onListings = function () use (&$atPurge): void {
            $atPurge = $this->storedBlocks();
        };

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Bitte beachten.'], $this->version(['footer.note']), ['de']));

        self::assertSame(['de' => ['footer.note' => 'Bitte beachten.']], $atPurge);
    }

    public function test_a_failed_refresh_keeps_the_save_and_still_purges(): void
    {
        $purges = $this->purger()->listings;
        $this->failRefreshes();

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Neu.'], $this->version(['footer.note']), ['de']));

        self::assertSame(['footer.note' => 'Neu.'], $this->bodies(['footer.note']));
        self::assertSame($purges + 1, $this->purger()->listings);
        self::assertNull($this->storedBlocks(), 'The failed refresh stored nothing.');
    }

    /**
     * The line an operator reads: the app has no Monolog, and the default logger prints the
     * message with its placeholders filled and nothing else of the context.
     */
    public function test_a_failed_refresh_logs_its_cause_and_not_the_exception_message(): void
    {
        self::assertInstanceOf(Logger::class, self::getContainer()->get('logger'));
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $this->failRefreshes();

        new RefreshContentBlockSnapshotHandler($this->contentBlocks(), new Logger(LogLevel::ERROR, $output))(new RefreshContentBlockSnapshot());

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Content blocks: the snapshot refresh failed ('.DriverException::class.' at ', $line);
        self::assertMatchesRegularExpression('~:\d+\)\.~', $line);
        self::assertStringNotContainsString('lock timeout', $line);
    }

    public function test_two_saves_of_one_subject_take_turns_on_its_lock(): void
    {
        $this->holdAdvisoryLock($this->connection, 'content_blocks:listing.intro');
        $this->connection->executeStatement("SET LOCAL lock_timeout = '50ms'");

        $failure = $this->failure(new SaveTextBlock('listing.intro', ['de' => 'Text.'], $this->version(['listing.intro']), ['de']));

        self::assertInstanceOf(DriverException::class, $failure);
        self::assertSame('55P03', $failure->getSQLState());
        self::assertSame([], $this->bodies(['listing.intro']));

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Bitte beachten.'], $this->version(['footer.note']), ['de']));
        self::assertSame(['footer.note' => 'Bitte beachten.'], $this->bodies(['footer.note']), 'Another subject has its own lock.');
    }

    public function test_a_save_that_changes_nothing_neither_purges_nor_moves_the_version(): void
    {
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Bitte beachten.'], $this->version(['footer.note']), ['de']));
        $version = $this->version(['footer.note']);
        $purges = $this->purger()->listings;

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => ' Bitte beachten. '], $version, ['de']));

        self::assertSame($purges, $this->purger()->listings);
        self::assertSame($version, $this->version(['footer.note']));
    }

    public function test_a_stale_version_throws_and_writes_nothing(): void
    {
        $loaded = $this->version(self::LANDING_KEYS);
        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['title' => 'Zürich feiert']], $loaded, ['title'], ['de']));
        $purges = $this->purger()->listings;
        $audits = \count($this->auditRows());

        $failure = $this->failure(new SaveLandingTexts('canton.zh', ['de' => ['title' => 'Zürich heiratet', 'intro' => 'Am See.']], $loaded, ['title', 'intro'], ['de']));

        self::assertInstanceOf(StaleContentBlocks::class, $failure);
        self::assertSame('landing.canton.zh', $failure->subject);
        self::assertSame(['landing.canton.zh.title' => 'Zürich feiert'], $this->bodies(self::LANDING_KEYS));
        self::assertSame($purges, $this->purger()->listings);
        self::assertCount($audits, $this->auditRows());
    }

    public function test_a_row_cleared_since_loading_makes_the_version_stale(): void
    {
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Bitte beachten.'], $this->version(['footer.note']), ['de']));
        $loaded = $this->version(['footer.note']);
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => null], $loaded, ['de']));

        $failure = $this->failure(new SaveTextBlock('footer.note', ['de' => 'Neu.'], $loaded, ['de']));

        self::assertInstanceOf(StaleContentBlocks::class, $failure);
        self::assertSame([], $this->bodies(['footer.note']));
    }

    public function test_a_revision_in_the_same_second_makes_the_version_stale(): void
    {
        self::mockTime('2026-10-31 10:00:00.250000 UTC');
        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Erster Text.'], $this->version(['footer.note']), ['de']));
        $loaded = $this->version(['footer.note']);

        $this->dispatch(new SaveTextBlock('footer.note', ['de' => 'Zweiter Text.'], $loaded, ['de']));
        self::assertSame(
            '2026-10-31 10:00:00',
            (string) $this->connection->fetchOne("SELECT to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM content_blocks WHERE key = 'footer.note'"),
            'Both writes fall in the same second.',
        );

        $purges = $this->purger()->listings;
        $failure = $this->failure(new SaveTextBlock('footer.note', ['de' => 'Dritter Text.'], $loaded, ['de']));

        self::assertInstanceOf(StaleContentBlocks::class, $failure);
        self::assertSame(['footer.note' => 'Zweiter Text.'], $this->bodies(['footer.note']));
        self::assertSame($purges, $this->purger()->listings);
    }

    public function test_the_token_of_database_rows_equals_the_token_of_the_entities(): void
    {
        self::assertSame(hash('sha256', ''), $this->version(self::LANDING_KEYS), 'No rows give the token of the empty string.');

        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Am See.', 'title' => 'Zürich feiert']], $this->version(self::LANDING_KEYS), ['intro', 'title'], ['de']));

        $repository = self::getContainer()->get(ContentBlockRepository::class);
        self::assertInstanceOf(ContentBlockRepository::class, $repository);
        $managed = $repository->byKeys(self::LANDING_KEYS);
        self::assertCount(2, $managed);
        self::assertSame($this->version(self::LANDING_KEYS), ContentVersion::of($managed), 'The entities still holding the clock\'s microseconds give the database rows\' token.');

        $this->em->clear();
        self::assertSame($this->version(self::LANDING_KEYS), ContentVersion::of($repository->byKeys(self::LANDING_KEYS)));
    }

    public function test_an_unknown_landing_page_throws(): void
    {
        foreach (['canton.be', 'canton.zh.title', 'kanton.zh', 'town.00000000-0000-0000-0000-000000000000'] as $keyPrefix) {
            $failure = $this->failure(new SaveLandingTexts($keyPrefix, ['de' => ['title' => 'Titel']], hash('sha256', ''), ['title'], ['de']));
            self::assertInstanceOf(UnknownLandingPage::class, $failure, $keyPrefix);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM content_blocks'));
    }

    public function test_an_unknown_text_block_throws(): void
    {
        $failure = $this->failure(new SaveTextBlock('listing.outro', ['de' => 'Text'], hash('sha256', ''), ['de']));

        self::assertInstanceOf(UnknownContentBlock::class, $failure);
        self::assertSame('listing.outro', $failure->key);
    }

    public function test_a_text_over_its_limit_is_refused_and_nothing_is_written(): void
    {
        $purges = $this->purger()->listings;
        $title = str_repeat('ä', 59).'😀';

        $failure = $this->failure(new SaveLandingTexts('canton.zh', ['de' => ['intro' => 'Am See.', 'title' => $title.'x']], $this->version(self::LANDING_KEYS), ['intro', 'title'], ['de']));
        self::assertInstanceOf(ContentTooLong::class, $failure);
        self::assertSame(['title', 'de', 60], [$failure->field, $failure->locale, $failure->maxLength]);
        self::assertSame([], $this->bodies(self::LANDING_KEYS));

        $failure = $this->failure(new SaveTextBlock('footer.note', ['de' => str_repeat('a', 301)], $this->version(['footer.note']), ['de']));
        self::assertInstanceOf(ContentTooLong::class, $failure);
        self::assertSame(['body', 'de', 300], [$failure->field, $failure->locale, $failure->maxLength]);
        self::assertSame($purges, $this->purger()->listings);

        $this->dispatch(new SaveLandingTexts('canton.zh', ['de' => ['title' => '  '.$title."\n"]], $this->version(self::LANDING_KEYS), ['title'], ['de']));
        self::assertSame(['landing.canton.zh.title' => $title], $this->bodies(self::LANDING_KEYS), 'The limit counts code points of the trimmed text.');
    }

    /** The refresh waits for the snapshot lock and gives up, as it would on a failing database. */
    private function failRefreshes(): void
    {
        $this->holdAdvisoryLock($this->connection, ContentBlocks::LOCK);
        $this->connection->executeStatement("SET LOCAL lock_timeout = '50ms'");
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($command);
    }

    private function failure(object $command): \Throwable
    {
        try {
            $this->dispatch($command);
        } catch (HandlerFailedException $failure) {
            $wrapped = $failure->getWrappedExceptions();

            return reset($wrapped) ?: $failure;
        }

        self::fail('The command should have failed.');
    }

    /**
     * The editor's token, from the database rows as the back office reads them.
     *
     * @param list<string> $keys
     */
    private function version(array $keys): string
    {
        /** @var list<array{key: string, locale: string, updated_at: string, body_markdown: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT key, locale, updated_at, body_markdown FROM content_blocks WHERE key IN (?)',
            [$keys],
            [ArrayParameterType::STRING],
        );

        return ContentVersion::of($rows);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> key → body of the `de` rows
     */
    private function bodies(array $keys): array
    {
        /** @var array<string, string> $bodies */
        $bodies = $this->connection->fetchAllKeyValue(
            "SELECT key, body_markdown FROM content_blocks WHERE locale = 'de' AND key IN (?) ORDER BY key",
            [$keys],
            [ArrayParameterType::STRING],
        );

        return $bodies;
    }

    /**
     * @return list<array{command: string, subject_type: string, subject_id: string, changes: mixed}>
     */
    private function auditRows(): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT command, subject_type, subject_id, changes FROM admin_audit_log WHERE subject_type IN ('landing_page', 'content_block') ORDER BY created_at, id");

        return array_map(static fn (array $row): array => [
            'command' => (string) $row['command'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (string) $row['subject_id'],
            'changes' => json_decode((string) $row['changes'], true, flags: \JSON_THROW_ON_ERROR),
        ], $rows);
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }

    private function contentBlocks(): ContentBlocks
    {
        $blocks = self::getContainer()->get(ContentBlocks::class);
        self::assertInstanceOf(ContentBlocks::class, $blocks);

        return $blocks;
    }

    /**
     * @return array<mixed>|null locale → key → body of the pool's item
     */
    private function storedBlocks(): ?array
    {
        $pool = self::getContainer()->get('cache.content_blocks');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $item = $pool->getItem(ContentBlocks::ITEM);
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();
        self::assertIsArray($value);
        self::assertIsArray($value['blocks'] ?? null);

        return $value['blocks'];
    }
}
