<?php

declare(strict_types=1);

namespace App\Cms\Exception;

use App\Shared\Exception\DomainException;

/**
 * A text is longer than its limit (#26), counted in code points on the trimmed text as the
 * form counts it. The form refuses it first; this is the handler's rule for every other
 * caller. The editor answers 422 on that field. The message names the field, never the text.
 */
final class ContentTooLong extends DomainException
{
    /**
     * @param string $field `intro`, `title`, `description`, or `body` for a text block
     */
    private function __construct(
        public readonly string $field,
        public readonly string $locale,
        public readonly int $maxLength,
    ) {
        parent::__construct(\sprintf('The %s text "%s" is longer than %d characters.', $locale, $field, $maxLength));
    }

    public static function forField(string $field, string $locale, int $maxLength): self
    {
        return new self($field, $locale, $maxLength);
    }

    /**
     * @throws self when the trimmed text has more than $maxLength code points
     */
    public static function check(?string $text, string $field, string $locale, int $maxLength): void
    {
        if (null !== $text && mb_strlen(trim($text)) > $maxLength) {
            throw self::forField($field, $locale, $maxLength);
        }
    }
}
