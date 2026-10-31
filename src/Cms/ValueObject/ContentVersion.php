<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use App\Cms\Entity\ContentBlock;

/**
 * The stale-edit token of one editor (#26, P8): a SHA-256 over the sorted lines
 * `key|locale|updated_at|sha256(body)` of every row the editor covers, all locales. A row
 * added, removed or revised since the form was loaded changes it; no rows give the token of ''.
 *
 * `updated_at` enters as Unix seconds, because the column holds whole seconds: an entity still
 * holding the microseconds of the clock and the row read back from the database then give the
 * same line. The body hash is
 * what tells a revision in the same second apart; a same-second save of the same body is no
 * conflict.
 *
 * The editor computes it from DBAL rows (`SELECT key, locale, updated_at, body_markdown`), the
 * handler from the entities it locked; both give the same token.
 */
final readonly class ContentVersion
{
    /**
     * @param list<ContentBlock|array{key: string, locale: string, updated_at: string, body_markdown: string}> $rows
     */
    public static function of(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $row instanceof ContentBlock
                ? self::line($row->key(), $row->locale(), $row->updatedAt(), $row->body())
                : self::line($row['key'], $row['locale'], new \DateTimeImmutable($row['updated_at']), $row['body_markdown']);
        }
        sort($lines, \SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    private static function line(string $key, string $locale, \DateTimeImmutable $updatedAt, string $body): string
    {
        return $key.'|'.$locale.'|'.$updatedAt->format('U').'|'.hash('sha256', $body);
    }
}
