<?php

declare(strict_types=1);

namespace App\Shared\Translation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single source of truth for the locales the site serves (framework.enabled_locales) and its
 * default locale. hreflang alternates iterate all(); the write side of content translations
 * validates a row's locale with isEnabled(); the read side falls back to default().
 */
final class EnabledLocale
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    public function isEnabled(string $locale): bool
    {
        return \in_array($locale, $this->enabledLocales, true);
    }

    public function clamp(string $locale): string
    {
        return $this->isEnabled($locale) ? $locale : $this->defaultLocale;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->enabledLocales;
    }

    public function default(): string
    {
        return $this->defaultLocale;
    }
}
