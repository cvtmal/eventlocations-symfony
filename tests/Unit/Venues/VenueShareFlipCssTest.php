<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use PHPUnit\Framework\TestCase;

/**
 * With the button at the viewport's foot WebKit leaves the anchored menu past the fold, where the scroll lock keeps it
 * out of reach. Chromium and Firefox hold it inside; the fallback puts it above the button in all three.
 */
final class VenueShareFlipCssTest extends TestCase
{
    public function test_the_anchored_menu_flips_above_its_button(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/venues/detail/_actions.scss');

        self::assertMatchesRegularExpression('/@supports \(position-anchor: --a\) \{\s+position-anchor: --venue-share;\s+position-area: block-end;\s+position-try-fallbacks: flip-block;/', $partial);
    }
}
