<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * The reset link has no padding and sits last in the overlay, so Tab scrolled it flush with the bottom edge and the
 * overlay cut its 4 px ring (no-JS Region at 390). Its scroll margin must stay wider than the ring.
 */
final class OverlayClearFocusCssTest extends TestCase
{
    public function test_the_reset_link_scrolls_clear_of_the_edge(): void
    {
        $partial = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/styles/components/_overlay.scss');

        self::assertMatchesRegularExpression('/\.overlay__clear \{[^{}]*scroll-margin-block: var\(--space-8\);/', $partial);
    }
}
