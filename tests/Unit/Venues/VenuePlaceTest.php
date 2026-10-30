<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Geo\ValueObject\Canton;
use App\Venues\ValueObject\VenuePlace;
use PHPUnit\Framework\TestCase;

final class VenuePlaceTest extends TestCase
{
    public function test_a_town_is_shown_as_it_is(): void
    {
        $place = VenuePlace::of('Stäfa', 'zh', null);

        self::assertTrue($place->isKnown());
        self::assertSame(Canton::ZH, $place->canton);
        self::assertSame('Stäfa', $place->trans(new RecordingTranslator()));
    }

    public function test_a_canton_alone_goes_through_the_venues_catalogue(): void
    {
        $translator = new RecordingTranslator();
        $place = VenuePlace::of(null, 'be', null);

        self::assertTrue($place->isKnown());
        self::assertSame('label', $place->trans($translator));
        self::assertSame(
            [['canton.be', 'geo'], ['place.canton', 'venues']],
            array_map(static fn (array $call): array => [$call[0], $call[2]], $translator->calls),
        );
    }

    public function test_a_region_text_is_shown_verbatim(): void
    {
        $translator = new RecordingTranslator();
        $place = VenuePlace::of(null, null, 'Schweizweit');

        self::assertTrue($place->isKnown());
        self::assertSame('Schweizweit', $place->trans($translator));
        self::assertSame([], $translator->calls);
    }

    public function test_nothing_known_gives_an_empty_line(): void
    {
        $place = VenuePlace::of(null, null, null);

        self::assertFalse($place->isKnown());
        self::assertSame('', $place->trans(new RecordingTranslator()));
    }
}
