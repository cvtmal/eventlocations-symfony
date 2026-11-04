<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * Blink and WebKit match `:focus-visible` on a select tapped by finger, so the keyboard's scroll padding moved More by
 * 28 px under a phone's tap. The select case alone needs a fine pointer; every other control keeps the padding on any
 * device, so a phone with a hardware keyboard or iOS Full Keyboard Access still clears the band (#41 F-m2).
 */
final class MoreSelectTapCssTest extends TestCase
{
    public function test_keyboard_padding_needs_a_fine_pointer_on_a_select_only(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/search/_filter-more.scss');

        // The open overlay's own block, so a move under another rule fails (#41 R1, F-m3).
        self::assertSame(1, preg_match('/^\.overlay--more:popover-open \{\n(.*?)^\}/ms', $partial, $block));
        $body = $block[1] ?? '';
        self::assertMatchesRegularExpression('/^    &:has\(:focus-visible:not\(select\)\) \{\s+scroll-padding-bottom: 10rem;\s+\}/m', $body);
        self::assertMatchesRegularExpression('/^    @media \(any-pointer: fine\) \{\s+&:has\(select:focus-visible\) \{\s+scroll-padding-bottom: 10rem;/m', $body);
        self::assertSame(2, substr_count($partial, 'scroll-padding-bottom:'));
        // No plain `:has(:focus-visible)` is left, and no later longhand or shorthand overrides the two.
        self::assertStringNotContainsString(':has(:focus-visible)', $partial);
        self::assertSame(0, preg_match('/scroll-padding(?:-block)?(?:-end)?:/', $partial));
    }
}
