<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A translator that returns the key, to read one out of a TranslatableInterface.
 */
final class KeyEcho implements TranslatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return 'de';
    }
}
