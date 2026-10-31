<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

/**
 * One landing page as the back office lists and edits it (#26). The defaults are what the
 * public page shows without a custom text: the intro with its count, #17's title chain and
 * description.
 */
final readonly class LandingPageEntry
{
    public const array FIELDS = ['intro', 'title', 'description'];

    /**
     * @param 'canton'|'town'|'building' $type
     * @param string|null                $url  absolute public URL, only for a page that passes the threshold
     */
    public function __construct(
        public string $keyPrefix,
        public string $type,
        public string $name,
        public int $venueCount,
        public bool $passesThreshold,
        public ?string $url,
        public string $defaultIntro,
        public string $defaultTitle,
        public string $defaultDescription,
    ) {
    }

    /**
     * The content_blocks key of one field: `landing.canton.zh.title`.
     */
    public function blockKey(string $field): string
    {
        return 'landing.'.$this->keyPrefix.'.'.$field;
    }
}
