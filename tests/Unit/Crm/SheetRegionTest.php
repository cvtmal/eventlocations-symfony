<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\SheetRegion;
use App\Geo\ValueObject\Canton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SheetRegionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function regions(): iterable
    {
        yield 'Zürich' => ['Zürich', ['zh']];
        yield 'Graubünden' => ['Graubünden', ['gr']];
        yield 'St. Gallen' => ['St. Gallen', ['sg']];
        yield 'Basel-Stadt' => ['Basel-Stadt', ['bs']];
        yield 'Basel-Landschaft' => ['Basel-Landschaft', ['bl']];
        yield 'Appenzell Ausserrhoden' => ['Appenzell Ausserrhoden', ['ar']];
        yield 'Neuchâtel in French' => ['Neuchâtel', ['ne']];
        yield 'upper case and a stray space' => [' ZÜRICH ', ['zh']];
        yield 'Basel' => ['Basel', ['bs', 'bl']];
        yield 'Appenzell' => ['Appenzell', ['ai', 'ar']];
        yield 'Zentralschweiz' => ['Zentralschweiz', ['lu', 'ur', 'sz', 'ow', 'nw', 'zg']];
        yield 'Ostschweiz' => ['Ostschweiz', ['sg', 'tg', 'ar', 'ai', 'gl', 'sh', 'gr']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('regions')]
    public function test_it_maps_a_region_to_its_cantons(string $text, array $codes): void
    {
        $region = SheetRegion::fromSheet($text);

        self::assertTrue($region->isKnown());
        self::assertFalse($region->isUnrecognised());
        self::assertSame($codes, $region->codes());
    }

    public function test_every_canton_label_of_the_geo_catalogue_is_known(): void
    {
        $labels = [
            'Zürich', 'Bern', 'Luzern', 'Uri', 'Schwyz', 'Obwalden', 'Nidwalden', 'Glarus', 'Zug', 'Freiburg', 'Solothurn',
            'Basel-Stadt', 'Basel-Landschaft', 'Schaffhausen', 'Appenzell Ausserrhoden', 'Appenzell Innerrhoden', 'St. Gallen',
            'Graubünden', 'Aargau', 'Thurgau', 'Tessin', 'Waadt', 'Wallis', 'Neuenburg', 'Genf', 'Jura', 'Liechtenstein',
        ];

        $codes = array_map(static fn (string $label): array => SheetRegion::fromSheet($label)->codes(), $labels);

        self::assertSame(array_map(static fn (Canton $canton): array => [$canton->value], Canton::cases()), $codes);
    }

    public function test_zentralschweiz_fits_zug(): void
    {
        $region = SheetRegion::fromSheet('Zentralschweiz');

        self::assertTrue($region->compatibleWithCanton('zg'));
        self::assertTrue($region->compatibleWithCanton('ZG'));
        self::assertTrue($region->compatibleWith(SheetRegion::fromSheet('Zug')));
        self::assertFalse($region->compatibleWithCanton('be'));
        self::assertFalse($region->compatibleWith(SheetRegion::fromSheet('Bern')));
    }

    public function test_basel_fits_both_half_cantons(): void
    {
        $region = SheetRegion::fromSheet('Basel');

        self::assertTrue($region->compatibleWithCanton('bl'));
        self::assertTrue($region->compatibleWithCanton('bs'));
        self::assertFalse($region->compatibleWithCanton('ag'));
    }

    public function test_liechtenstein_fits_only_itself(): void
    {
        $region = SheetRegion::fromSheet('Liechtenstein');

        self::assertTrue($region->isKnown());
        foreach (Canton::cases() as $canton) {
            self::assertSame(Canton::LI === $canton, $region->compatibleWithCanton($canton->value), $canton->value);
        }
        self::assertFalse($region->compatibleWith(SheetRegion::fromSheet('Ostschweiz')));
        self::assertTrue($region->compatibleWith(SheetRegion::fromSheet('Liechtenstein')));
    }

    public function test_an_empty_cell_is_unknown_and_fits_everything(): void
    {
        $region = SheetRegion::fromSheet(" \u{200B}");

        self::assertFalse($region->isKnown());
        self::assertFalse($region->isUnrecognised());
        self::assertNull($region->label());
        self::assertSame([], $region->codes());
        self::assertNull($region->compatibleWithCanton('zh'));
        self::assertTrue($region->compatibleWith(SheetRegion::fromSheet('Bern')));
        self::assertTrue(SheetRegion::fromSheet('Bern')->compatibleWith($region));
    }

    public function test_an_unknown_text_is_unknown_and_flagged(): void
    {
        $region = SheetRegion::fromSheet('Vorarlberg');

        self::assertFalse($region->isKnown());
        self::assertTrue($region->isUnrecognised());
        self::assertSame('Vorarlberg', $region->label());
        self::assertNull($region->compatibleWithCanton('sg'));
        self::assertTrue($region->compatibleWith(SheetRegion::fromSheet('St. Gallen')));
    }

    public function test_a_venue_without_a_canton_is_unknown(): void
    {
        self::assertNull(SheetRegion::fromSheet('Bern')->compatibleWithCanton(null));
    }

    public function test_the_label_is_the_cleaned_cell(): void
    {
        self::assertSame('St. Gallen', SheetRegion::fromSheet("St.\u{00A0}Gallen ")->label());
    }

    public function test_a_canton_is_more_specific_than_a_wider_region_and_any_region_than_none(): void
    {
        $canton = SheetRegion::fromSheet('Schwyz');
        $central = SheetRegion::fromSheet('Zentralschweiz');
        $basel = SheetRegion::fromSheet('Basel');
        $unknown = SheetRegion::fromSheet('');

        self::assertTrue($canton->isMoreSpecificThan($central));
        self::assertFalse($central->isMoreSpecificThan($canton));
        self::assertTrue($basel->isMoreSpecificThan($central));
        self::assertTrue($central->isMoreSpecificThan($unknown));
        self::assertFalse($unknown->isMoreSpecificThan($central));
        self::assertFalse($canton->isMoreSpecificThan(SheetRegion::fromSheet('Zug')));
    }
}
