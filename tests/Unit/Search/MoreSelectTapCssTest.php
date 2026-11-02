<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * Blink and WebKit match `:focus-visible` on a select tapped by finger, so the keyboard's scroll padding moved More by
 * 28 px under a phone's tap. The padding is for Tab and stays on devices with a fine pointer only.
 */
final class MoreSelectTapCssTest extends TestCase
{
    public function test_keyboard_padding_needs_a_fine_pointer(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_filter-more.scss');

        // The open overlay's own block, so a move under another rule fails (#41 R1, F-m3).
        self::assertSame(1, preg_match('/^\.overlay--more:popover-open \{\n(.*?)^\}/ms', $partial, $block));
        self::assertMatchesRegularExpression('/^    @media \(any-pointer: fine\) \{\s+&:has\(:focus-visible\) \{\s+scroll-padding-bottom: 10rem;/m', $block[1] ?? '');
        self::assertSame(1, substr_count($partial, 'scroll-padding-bottom:'));
        // No later longhand or shorthand overrides it.
        self::assertSame(0, preg_match('/scroll-padding(?:-block)?(?:-end)?:/', $partial));
    }
}
