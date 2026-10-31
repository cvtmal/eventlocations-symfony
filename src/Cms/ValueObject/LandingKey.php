<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

/**
 * A landing page as the back office names it (#26, P11): the key prefix of its content_blocks
 * rows (`canton.zh`, `town.<uuid>`, `building.brewery_winery`), and the limits of its three
 * texts. The limits are #16's: a title of at most 60 and a description of at most 155 code
 * points, counted on the trimmed text, as the editor's counter does.
 */
final readonly class LandingKey
{
    public const string REQUIREMENT = '(?:canton|town|building)\.[a-z0-9_-]+';

    public const int INTRO_MAX_LENGTH = 600;
    public const int TITLE_MAX_LENGTH = 60;
    public const int DESCRIPTION_MAX_LENGTH = 155;

    /** @var array<string, int> field → code points */
    public const array MAX_LENGTHS = [
        'intro' => self::INTRO_MAX_LENGTH,
        'title' => self::TITLE_MAX_LENGTH,
        'description' => self::DESCRIPTION_MAX_LENGTH,
    ];

    public static function isValid(string $keyPrefix): bool
    {
        return 1 === preg_match('/\A'.self::REQUIREMENT.'\z/', $keyPrefix);
    }

    /**
     * @throws \InvalidArgumentException for a field other than intro, title or description
     */
    public static function maxLength(string $field): int
    {
        return self::MAX_LENGTHS[$field] ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a landing page text.', $field));
    }
}
