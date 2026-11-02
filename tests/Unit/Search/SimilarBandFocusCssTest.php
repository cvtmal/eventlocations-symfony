<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * Below lg the similar band's peek leaves the odd slide half-shown on a keyboard stop, and its track runs to the
 * viewport edge. The keyboard walk of #41 proves the behaviour; this keeps the two values that behaviour hangs on.
 */
final class SimilarBandFocusCssTest extends TestCase
{
    public function test_the_snapport_stays_wider_than_a_slide(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_similar-venues.scss');

        if (1 !== preg_match('/scroll-padding-right: calc\(100% - ([\d.]+)rem\);/', $partial, $padding)
            || 1 !== preg_match('/\.carousel__slide \{\s+flex-basis: ([\d.]+)rem;/', $partial, $slide)) {
            self::fail('_similar-venues.scss no longer holds the scroll padding or the slide width.');
        }

        self::assertGreaterThan((float) $slide[1], (float) $padding[1]);
    }

    public function test_the_track_ring_stays_inside_the_viewport(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_similar-venues.scss');

        self::assertMatchesRegularExpression('/scroll-padding-right:[^}]+&:focus-visible \{\s+outline-offset: -2px;/', $partial);
    }

    /**
     * WebKit snaps on past a key's target and skips every other slide at 390 px. Set for a pointer too, the stop would
     * hold a swipe to one card at a time in every browser.
     */
    public function test_a_keyboard_stops_at_every_slide_and_a_swipe_does_not(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_similar-venues.scss');

        self::assertMatchesRegularExpression('/@include bp\.down\(md\) \{\s+&:focus-visible \.carousel__slide \{\s+scroll-snap-stop: always;/', $partial);
        self::assertSame(1, substr_count($partial, 'scroll-snap-stop:'));
    }
}
