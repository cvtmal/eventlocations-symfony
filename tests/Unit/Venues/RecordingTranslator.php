<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Records every trans() call so a test can assert the key and the domain.
 */
final class RecordingTranslator implements TranslatorInterface
{
    /** @var list<array{string, array<mixed>, ?string, ?string}> */
    public array $calls = [];

    /**
     * @param array<mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->calls[] = [$id, $parameters, $domain, $locale];

        return 'label';
    }

    public function getLocale(): string
    {
        return 'de';
    }
}
