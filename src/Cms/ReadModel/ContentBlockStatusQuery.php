<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Cms\ValueObject\ContentVersion;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What the back office's content screens read of `content_blocks` (#26): which subjects have rows and since
 * when (the lists), and one subject's bodies and version token (the editors). DBAL only.
 */
final class ContentBlockStatusQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The newest `updated_at` per subject among the keys starting with $prefix: a landing page's three keys
     * (`landing.<type>.<id>.<field>`) count as one subject `landing.<type>.<id>`, a block's key is its own.
     * One query.
     */
    public function byPrefix(string $prefix): ContentBlockChanges
    {
        $rows = $this->connection->fetchAllKeyValue(
            <<<'SQL'
                SELECT CASE WHEN starts_with(key, 'landing.')
                            THEN split_part(key, '.', 1) || '.' || split_part(key, '.', 2) || '.' || split_part(key, '.', 3)
                            ELSE key END AS subject,
                       MAX(updated_at) AS updated_at
                FROM content_blocks
                WHERE starts_with(key, :prefix)
                GROUP BY subject
                SQL,
            ['prefix' => $prefix],
        );

        $changes = [];
        foreach ($rows as $subject => $updatedAt) {
            $changes[(string) $subject] = new \DateTimeImmutable((string) $updatedAt);
        }

        return new ContentBlockChanges($changes);
    }

    /**
     * The token the editor sends back with its form (P8). Read it before bodies(): a save in between then
     * gives a spurious 409, never a silent overwrite.
     *
     * @param list<string> $keys every key the editor covers
     */
    public function versionOf(array $keys): string
    {
        /** @var list<array{key: string, locale: string, updated_at: string, body_markdown: string}> $rows */
        $rows = [] === $keys ? [] : $this->connection->fetchAllAssociative(
            'SELECT key, locale, updated_at, body_markdown FROM content_blocks WHERE key IN (:keys)',
            ['keys' => $keys],
            ['keys' => ArrayParameterType::STRING],
        );

        return ContentVersion::of($rows);
    }

    /**
     * @param list<string> $keys
     */
    public function bodies(array $keys): ContentBlockBodies
    {
        if ([] === $keys) {
            return new ContentBlockBodies([]);
        }

        $bodies = [];
        foreach ($this->connection->iterateAssociative(
            'SELECT key, locale, body_markdown FROM content_blocks WHERE key IN (:keys)',
            ['keys' => $keys],
            ['keys' => ArrayParameterType::STRING],
        ) as $row) {
            $bodies[(string) $row['locale']][(string) $row['key']] = (string) $row['body_markdown'];
        }

        return new ContentBlockBodies($bodies);
    }
}
