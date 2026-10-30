<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\RatingHighlight;
use PHPUnit\Framework\TestCase;

final class RatingHighlightTest extends TestCase
{
    private const SPRITE = __DIR__.'/../../../assets/icons/sprite.svg';

    public function test_nine_highlights_in_spec_order(): void
    {
        self::assertSame(
            ['food', 'atmosphere', 'architecture', 'location', 'hospitality', 'reachability', 'equipment', 'programme', 'value'],
            array_map(static fn (RatingHighlight $highlight): string => $highlight->value, RatingHighlight::cases()),
        );
    }

    public function test_every_icon_is_a_symbol_of_the_sprite(): void
    {
        $sprite = file_get_contents(self::SPRITE);
        self::assertIsString($sprite);
        preg_match_all('/<symbol\b[^>]*\bid="([^"]+)"/', $sprite, $matches);

        foreach (RatingHighlight::cases() as $highlight) {
            self::assertContains($highlight->icon(), $matches[1], $highlight->value);
        }
    }
}
