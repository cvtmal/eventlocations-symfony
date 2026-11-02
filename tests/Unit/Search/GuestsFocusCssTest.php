<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * At 200 % Firefox leaves a focused bubble partly out of frame and the others stop flush with the edge. The padding must
 * stay off the field: `overlay.js` focuses it on opening, and the overlay has to open at its top.
 */
final class GuestsFocusCssTest extends TestCase
{
    public function test_only_a_bubble_or_the_footer_centres_on_focus(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_filter-guests.scss');

        self::assertMatchesRegularExpression('/&:has\(\.guests-bubble:focus-visible, \.overlay__footer :focus-visible\) \{\s+scroll-padding-block: 50%;/', $partial);
        self::assertSame(1, substr_count($partial, 'scroll-padding-block:'));
    }
}
