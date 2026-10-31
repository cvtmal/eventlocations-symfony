<?php

declare(strict_types=1);

namespace App\Shared\ReadModel;

use App\Shared\Markdown\MarkdownRenderer;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Staff-edited text blocks (#26), read from one snapshot of the whole content_blocks table in
 * `cache.content_blocks` (item `snapshot`: build time, `locale → key → body`), so a page reads them without
 * a query once the pool is warm and never with a session (S10).
 *
 * - text()/html() fill a cold pool with one query; listing and landing pages use them.
 * - peek()/peekHtml() read the pool only and never query, for the footer note on every page,
 *   the query-free error pages included (#16's ErrorPagesQueryFreeTest).
 * - refresh() rebuilds the snapshot after a CMS save has committed, so a warm pool never goes
 *   cold and a page that only peeks keeps showing the note.
 *
 * Every write to the pool happens under one transaction-level advisory lock, taken before the
 * SELECT: a refresh waits for it and a cold read skips the save when it is taken, so whoever saves
 * last has read every commit that came before its refresh.
 *
 * The item never expires, because a page that only peeks must keep its note and a cached listing
 * may be all that would warm an empty pool. Instead text() rebuilds a snapshot older than
 * MAX_AGE_SECONDS while peek() keeps serving it, which caps what no lock covers: a refresh that
 * failed or died after the commit, a restore, a direct SQL change. text() runs only on a PHP render,
 * and #19 caches listings until Zurich midnight, so a snapshot built just after midnight can serve
 * another cached day: the cap is about two days.
 *
 * A locale without a row gives null, never another locale's text: the page shows its own
 * catalogue text instead.
 */
final class ContentBlocks implements ResetInterface
{
    public const string ITEM = 'snapshot';

    public const string LOCK = 'content_blocks:snapshot';

    public const int MAX_AGE_SECONDS = 86400;

    /** @var array<string, array<string, string>>|null */
    private ?array $snapshot = null;

    private bool $aged = false;

    /** @var array<string, ?string> */
    private array $html = [];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'cache.content_blocks')]
        private readonly CacheItemPoolInterface $pool,
        private readonly MarkdownRenderer $markdown,
        private readonly ClockInterface $clock,
    ) {
    }

    public function text(string $key, string $locale): ?string
    {
        $this->load();
        if (null === $this->snapshot || $this->aged) {
            $this->replace($this->rebuild(false));
        }

        return $this->snapshot[$locale][$key] ?? null;
    }

    public function html(string $key, string $locale): ?string
    {
        return $this->render($key, $locale, $this->text($key, $locale));
    }

    public function peek(string $key, string $locale): ?string
    {
        $this->load();

        return $this->snapshot[$locale][$key] ?? null;
    }

    public function peekHtml(string $key, string $locale): ?string
    {
        return $this->render($key, $locale, $this->peek($key, $locale));
    }

    public function refresh(): void
    {
        $this->replace($this->rebuild(true));
    }

    public function reset(): void
    {
        $this->snapshot = null;
        $this->aged = false;
        $this->html = [];
    }

    /**
     * Takes the pool's snapshot into the request. A miss is not memoized, so a later text() fills it.
     */
    private function load(): void
    {
        if (null !== $this->snapshot) {
            return;
        }

        $stored = $this->stored();
        if (null !== $stored) {
            $this->snapshot = $stored['blocks'];
            $this->aged = $this->clock->now()->getTimestamp() - $stored['builtAt'] >= self::MAX_AGE_SECONDS;
        }
    }

    /**
     * @param array<string, array<string, string>> $snapshot
     */
    private function replace(array $snapshot): void
    {
        $this->snapshot = $snapshot;
        $this->aged = false;
        $this->html = [];
    }

    private function render(string $key, string $locale, ?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        return $this->html[$locale."\n".$key] ??= self::forPage($this->markdown->render($text));
    }

    /**
     * A page has one h1, so `#` steps down to h2; with `html_input: escape` that tag can only come
     * from a heading. MarkdownRenderer stays as it is for the rest of the back office. A body with
     * no visible text (a lone link reference, only NBSP) counts as no block, so the fallback shows.
     */
    private static function forPage(string $html): ?string
    {
        if ('' === preg_replace('/[\s\p{Z}]+/u', '', strip_tags($html))) {
            return null;
        }

        return preg_replace('~<(/?)h1>~', '<$1h2>', $html);
    }

    /**
     * The pool's snapshot, or null on a miss, a value of any other shape or a cache error.
     *
     * @return array{builtAt: int, blocks: array<string, array<string, string>>}|null
     */
    private function stored(): ?array
    {
        try {
            $item = $this->pool->getItem(self::ITEM);
            $value = $item->isHit() ? $item->get() : null;
        } catch (\Throwable) {
            return null;
        }

        if (!\is_array($value) || !\is_int($value['built_at'] ?? null) || !\is_array($value['blocks'] ?? null)) {
            return null;
        }

        $snapshot = [];
        foreach ($value['blocks'] as $locale => $bodies) {
            if (!\is_string($locale) || !\is_array($bodies)) {
                return null;
            }
            foreach ($bodies as $key => $body) {
                if (!\is_string($key) || !\is_string($body)) {
                    return null;
                }
                $snapshot[$locale][$key] = $body;
            }
        }

        return ['builtAt' => $value['built_at'], 'blocks' => $snapshot];
    }

    /**
     * Reads every row and stores the snapshot under the lock (see the class comment). A public
     * page never queues behind it; without the lock it serves what it read and stores nothing.
     * A cache failure must never fail a page; the next read queries again.
     *
     * @return array<string, array<string, string>>
     */
    private function rebuild(bool $wait): array
    {
        return $this->connection->transactional(function () use ($wait): array {
            $locked = true;
            if ($wait) {
                $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK]);
            } else {
                $locked = (bool) $this->connection->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK]);
            }

            $snapshot = [];
            foreach ($this->connection->fetchAllAssociative('SELECT key, locale, body_markdown FROM content_blocks ORDER BY locale, key') as $row) {
                $snapshot[(string) $row['locale']][(string) $row['key']] = (string) $row['body_markdown'];
            }

            if ($locked) {
                try {
                    $this->pool->save($this->pool->getItem(self::ITEM)->set(['built_at' => $this->clock->now()->getTimestamp(), 'blocks' => $snapshot]));
                } catch (\Throwable) {
                }
            }

            return $snapshot;
        });
    }
}
