<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Translation;

use App\Shared\Translation\EnabledLocale;
use PHPUnit\Framework\TestCase;

final class EnabledLocaleTest extends TestCase
{
    public function test_enabled_locale_is_kept(): void
    {
        self::assertSame('en', $this->enabledLocale()->clamp('en'));
    }

    public function test_locale_outside_enabled_list_falls_back_to_default(): void
    {
        self::assertSame('de', $this->enabledLocale()->clamp('fr'));
    }

    public function test_is_enabled(): void
    {
        self::assertTrue($this->enabledLocale()->isEnabled('de'));
        self::assertFalse($this->enabledLocale()->isEnabled('fr'));
    }

    public function test_all_lists_the_enabled_locales_in_order(): void
    {
        self::assertSame(['de', 'en'], $this->enabledLocale()->all());
    }

    public function test_default_is_the_default_locale(): void
    {
        self::assertSame('de', $this->enabledLocale()->default());
    }

    private function enabledLocale(): EnabledLocale
    {
        return new EnabledLocale(['de', 'en'], 'de');
    }
}
