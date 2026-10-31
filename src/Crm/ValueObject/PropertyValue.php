<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The canonical form of an inline-edited value (D11): trimmed, line breaks as LF, empty = null;
 * an e-mail also through ContactEmail. The field's loaded value is its version, so the handler,
 * the API's no-op check (P1) and RecordPropertyQuery compare canonical values only.
 */
final readonly class PropertyValue
{
    private function __construct()
    {
    }

    public static function canonical(?string $value, bool $email = false): ?string
    {
        if ($email) {
            return ContactEmail::normalise($value);
        }
        if (null === $value) {
            return null;
        }

        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return '' === $value ? null : $value;
    }
}
