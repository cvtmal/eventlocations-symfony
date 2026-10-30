<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Shared\Twig\SwissFormatExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

final class SwissFormatExtensionTest extends TestCase
{
    public function test_german_formats_use_swiss_conventions(): void
    {
        $extension = $this->extension('de');
        $value = new \DateTimeImmutable('2026-10-05T19:30:00Z');

        self::assertSame('05.10.2026', $extension->date($value));
        self::assertSame("1'234'567.5", $extension->number(1234567.5));
    }

    public function test_english_formats_use_swiss_conventions(): void
    {
        $extension = $this->extension('en');
        $value = new \DateTimeImmutable('2026-10-05T19:30:00Z');

        self::assertSame('5 Oct 2026', $extension->date($value));
        self::assertSame("1'234'567.5", $extension->number(1234567.5));
    }

    public function test_french_formats_use_swiss_conventions(): void
    {
        $extension = $this->extension('fr');
        $value = new \DateTimeImmutable('2026-10-05T19:30:00Z');

        self::assertSame('5 oct. 2026', $extension->date($value));
        // Grouped with the narrow no-break space, not a plain space.
        self::assertSame("1\u{202F}234\u{202F}567,5", $extension->number(1234567.5));
    }

    public function test_italian_formats_use_swiss_conventions(): void
    {
        $extension = $this->extension('it');
        $value = new \DateTimeImmutable('2026-10-05T19:30:00Z');

        self::assertSame('5 ott 2026', $extension->date($value));
        self::assertSame("1'234'567.5", $extension->number(1234567.5));
    }

    public function test_fixed_decimals_pad_with_zeros(): void
    {
        self::assertSame('7.50', $this->extension('de')->number(7.5, 2));
        self::assertSame('7.50', $this->extension('en')->number(7.5, 2));
        self::assertSame('7.50', $this->extension('it')->number(7.5, 2));
        self::assertSame('7,50', $this->extension('fr')->number(7.5, 2));
        self::assertSame('9', $this->extension('de')->number(9));
    }

    public function test_dates_are_shown_in_zurich_time(): void
    {
        self::assertSame('06.10.2026', $this->extension('de')->date(new \DateTimeImmutable('2026-10-05T22:30:00Z')));
    }

    public function test_range_uses_a_spaced_hyphen(): void
    {
        self::assertSame('50 - 450', $this->extension('de')->range(50, 450));
    }

    public function test_range_groups_thousands(): void
    {
        self::assertSame("1'000 - 1'500", $this->extension('de')->range(1000, 1500));
    }

    public function test_range_with_equal_bounds_is_one_number(): void
    {
        self::assertSame('120', $this->extension('de')->range(120, 120));
    }

    private function extension(string $locale): SwissFormatExtension
    {
        return new SwissFormatExtension(new LocaleSwitcher($locale, []));
    }
}
