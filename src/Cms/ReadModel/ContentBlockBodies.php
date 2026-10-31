<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * The stored texts of one editor's keys (#26). A key without a row has no body.
 */
final readonly class ContentBlockBodies
{
    /**
     * @param array<string, array<string, string>> $byLocale locale → key → body, as stored
     */
    public function __construct(
        private array $byLocale,
    ) {
    }

    public function of(string $locale, string $key): ?string
    {
        return $this->byLocale[$locale][$key] ?? null;
    }
}
