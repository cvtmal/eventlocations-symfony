<?php

declare(strict_types=1);

namespace App\Cms;

use App\Cms\Command\RefreshContentBlockSnapshot;
use App\Cms\Entity\ContentBlock;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Repository\ContentBlockRepository;
use App\Cms\ValueObject\ContentVersion;
use App\Venues\Command\PurgeListingsCache;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The one way a Cms handler writes content_blocks (#26). Call it inside the command's
 * transaction (every command.bus handler runs in one).
 *
 * - A transaction-level advisory lock on the subject serialises two saves of one editor, and
 *   the version token (ContentVersion, P8) is recomputed under it from the rows it is about
 *   to change, so a save after someone else's, an added or a cleared row included, throws
 *   StaleContentBlocks and writes nothing.
 * - A text that is null or blank after trimming removes its row (a block without text has
 *   none); otherwise the trimmed text is added or revised.
 * - When anything changed, after the commit, the content-block snapshot is rebuilt (P4) and
 *   then the listings are purged, in that order, so a page rendered after the purge already
 *   reads the new snapshot.
 */
final class ContentBlockWriter
{
    public function __construct(
        private readonly ContentBlockRepository $blocks,
        private readonly Connection $connection,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string                                $subject what the editor edits (`landing.canton.zh`, `listing.intro`), the lock's name
     * @param list<string>                          $keys    every key the editor covers; the version is over their rows in all locales
     * @param array<string, array<string, ?string>> $bodies  locale → key → text; a key left out stays as it is
     * @param string                                $version the token the form was loaded with
     *
     * @return bool whether a row was added, revised or removed
     *
     * @throws StaleContentBlocks
     */
    public function apply(string $subject, array $keys, array $bodies, string $version): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('ContentBlockWriter::apply() needs a transaction: dispatch the command on command.bus.');
        }

        $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['content_blocks:'.$subject]);

        $current = $this->blocks->byKeys($keys);
        if (!hash_equals(ContentVersion::of($current), $version)) {
            throw StaleContentBlocks::forSubject($subject);
        }

        $existing = [];
        foreach ($current as $block) {
            $existing[$block->locale()][$block->key()] = $block;
        }

        $now = $this->clock->now();
        $changed = false;
        foreach ($bodies as $locale => $texts) {
            foreach ($texts as $key => $text) {
                if (!\in_array($key, $keys, true)) {
                    throw new \InvalidArgumentException(\sprintf('The key "%s" is not one of the editor "%s".', $key, $subject));
                }

                $body = trim($text ?? '');
                $block = $existing[$locale][$key] ?? null;
                if ('' === $body) {
                    if (null !== $block) {
                        $this->blocks->remove($block);
                        $changed = true;
                    }
                } elseif (null === $block) {
                    $this->blocks->add(new ContentBlock($key, $locale, $body, $now));
                    $changed = true;
                } elseif ($block->revise($body, $now)) {
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $this->commandBus->dispatch(new RefreshContentBlockSnapshot(), [new DispatchAfterCurrentBusStamp()]);
            $this->commandBus->dispatch(new PurgeListingsCache(), [new DispatchAfterCurrentBusStamp()]);
        }

        return $changed;
    }
}
