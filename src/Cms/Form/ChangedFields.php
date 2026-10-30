<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\Util\StringUtil;

/**
 * What a card's submission changed against what was loaded (D11, P1). An empty list means a no-op save: no
 * command, no `cms_edited_at`, so an idle click blocks no import. The names go to the audit summary.
 *
 * Values are compared in a canonical form, so what a browser and the form do to an untouched value does not
 * count: strings are trimmed as `TrimListener` trims them, CRLF and CR fold to LF (a textarea submits CRLF),
 * `''` equals null (a form submits an empty field as null), enums compare by value, and lists are sorted (a
 * chip group submits in choice order, the database loads in position order).
 *
 * Public properties only, except the names in `IGNORED`: the stale token and the address card's button.
 * Nested objects and maps are walked, so a change names its path (`texts.de.summary`); a list is one value.
 */
final class ChangedFields
{
    public const array IGNORED = ['loadedAt', 'useTownCentre'];

    /**
     * @return list<string>
     */
    public static function between(object $before, object $after): array
    {
        $changed = [];
        self::diff(self::canonical($before), self::canonical($after), '', $changed);

        return $changed;
    }

    /**
     * @param list<string> $changed
     */
    private static function diff(mixed $before, mixed $after, string $path, array &$changed): void
    {
        if (self::isMap($before) || self::isMap($after)) {
            $before = \is_array($before) ? $before : [];
            $after = \is_array($after) ? $after : [];
            foreach (array_keys($before + $after) as $key) {
                self::diff($before[$key] ?? null, $after[$key] ?? null, '' === $path ? (string) $key : $path.'.'.$key, $changed);
            }

            return;
        }

        if ($before !== $after) {
            $changed[] = $path;
        }
    }

    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && [] !== $value && !array_is_list($value);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if (\is_string($value)) {
            $value = StringUtil::trim(str_replace(["\r\n", "\r"], "\n", $value));

            return '' === $value ? null : $value;
        }
        if (\is_object($value)) {
            $properties = [];
            // Called from this class, get_object_vars() sees the public properties only.
            foreach (get_object_vars($value) as $name => $property) {
                if (!\in_array($name, self::IGNORED, true)) {
                    $properties[$name] = self::canonical($property);
                }
            }

            return $properties;
        }
        if (\is_array($value)) {
            $canonical = array_map(self::canonical(...), $value);
            if (array_is_list($canonical)) {
                sort($canonical);
            }

            return $canonical;
        }

        return $value;
    }
}
