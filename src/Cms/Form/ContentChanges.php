<?php

declare(strict_types=1);

namespace App\Cms\Form;

/**
 * What a content editor's submission changed against the rows it was compared with (#26, P9), per locale,
 * in #24's canonical form (`ChangedFields`: trimmed, CRLF folded, `''` = null). Empty means a no-op save:
 * no command, no audit row, no purge.
 */
final readonly class ContentChanges
{
    /**
     * @param array<string, array<string, ?string>> $texts   locale → changed field → submitted text
     * @param list<string>                          $locales the locales with a change
     * @param list<string>                          $fields  the fields changed in any locale
     */
    private function __construct(
        public array $texts,
        public array $locales,
        public array $fields,
    ) {
    }

    /**
     * @param array<string, object> $before locale → field set as loaded
     * @param array<string, object> $after  locale → field set as submitted
     */
    public static function between(array $before, array $after): self
    {
        $texts = [];
        $fields = [];
        foreach ($after as $locale => $submitted) {
            $loaded = $before[$locale] ?? null;
            if (null === $loaded) {
                continue;
            }
            $values = get_object_vars($submitted);
            foreach (ChangedFields::between($loaded, $submitted) as $field) {
                $value = $values[$field] ?? null;
                $texts[$locale][$field] = \is_string($value) ? $value : null;
                $fields[$field] = true;
            }
        }

        $locales = array_map(strval(...), array_keys($texts));
        $fieldNames = array_map(strval(...), array_keys($fields));
        sort($locales);
        sort($fieldNames);

        return new self($texts, $locales, $fieldNames);
    }

    public function isEmpty(): bool
    {
        return [] === $this->texts;
    }
}
