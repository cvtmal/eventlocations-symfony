<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

/**
 * One document on the documents page (#25, CMS-7): titles, size, public URL and row token.
 */
final readonly class ManagedDocument
{
    /**
     * @param array<string, string> $titles locale → title, only the locales with a row
     * @param string                $url    `/media/<number>/<id>.pdf`
     * @param string                $token  the row token (`RowTokens::sql()` of `venue_documents.updated_at`)
     */
    public function __construct(
        public string $id,
        public int $position,
        public array $titles,
        public int $sizeBytes,
        public string $url,
        public string $token,
    ) {
    }

    /**
     * The title in the locale, else in the default locale, else ''.
     */
    public function title(string $locale, string $defaultLocale): string
    {
        return $this->titles[$locale] ?? $this->titles[$defaultLocale] ?? '';
    }
}
