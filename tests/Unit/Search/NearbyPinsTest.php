<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\NearbyPins;
use App\Search\ReadModel\NearbyVenue;
use App\Venues\ValueObject\VenuePlace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NearbyPinsTest extends TestCase
{
    public function test_venues_on_one_point_share_a_pin_in_the_given_order(): void
    {
        $pins = $this->decode([
            self::venue(1, 'Erste', 47.22890, 8.66960, 4.2),
            // About 5 m away: the same 4 dp point.
            self::venue(2, 'Zweite', 47.22893, 8.66962, 4.2),
            self::venue(3, 'Dritte', 47.30000, 8.70000, 12.6),
        ]);

        self::assertCount(2, $pins);
        self::assertSame(47.2289, $pins[0]['a']);
        self::assertSame(8.6696, $pins[0]['o']);
        self::assertSame(['Erste', 'Zweite'], array_column($pins[0]['v'], 'n'));
        self::assertSame(['Dritte'], array_column($pins[1]['v'], 'n'));
        self::assertSame('/hochzeitslocation/venue-1', $pins[0]['v'][0]['u']);
        self::assertSame('Stäfa', $pins[0]['v'][0]['p']);
        self::assertSame('4 km', $pins[0]['v'][0]['k']);
        self::assertSame('13 km', $pins[1]['v'][0]['k']);
    }

    public function test_a_venue_without_a_distance_has_no_km_and_a_close_one_shows_1_km(): void
    {
        $pins = $this->decode([
            self::venue(1, 'Ohne', 47.0, 8.0, null),
            self::venue(2, 'Nah', 46.0, 8.0, 0.2),
        ]);

        self::assertArrayNotHasKey('k', $pins[0]['v'][0]);
        self::assertSame('1 km', $pins[1]['v'][0]['k']);
    }

    public function test_a_name_with_markup_survives_unchanged(): void
    {
        $pins = $this->decode([self::venue(1, '<b>"&\'Schloss', 47.0, 8.0, null)]);

        self::assertSame('<b>"&\'Schloss', $pins[0]['v'][0]['n']);
    }

    public function test_an_unknown_place_is_empty(): void
    {
        $venue = new NearbyVenue(1, 'Irgendwo', 'irgendwo', VenuePlace::of(null, null, null), 47.0, 8.0, null);

        self::assertSame('', $this->decode([$venue])[0]['v'][0]['p']);
    }

    public function test_no_venues_give_an_empty_list(): void
    {
        self::assertSame('[]', $this->pins()->json([], 'de'));
    }

    /**
     * @param list<NearbyVenue> $venues
     *
     * @return list<array{a: float, o: float, v: list<array<string, string>>}>
     */
    private function decode(array $venues): array
    {
        /** @var list<array{a: float, o: float, v: list<array<string, string>>}> $pins */
        $pins = json_decode($this->pins()->json($venues, 'de'), true, 512, \JSON_THROW_ON_ERROR);

        return $pins;
    }

    private function pins(): NearbyPins
    {
        $urls = self::createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $name, array $parameters): string => '/hochzeitslocation/'.$parameters['slug'],
        );
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters): string => $parameters['km'].' km',
        );

        return new NearbyPins($urls, $translator);
    }

    private static function venue(int $number, string $name, float $lat, float $lng, ?float $km): NearbyVenue
    {
        return new NearbyVenue($number, $name, 'venue-'.$number, VenuePlace::of('Stäfa', 'zh', null), $lat, $lng, $km);
    }
}
