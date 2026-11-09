<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;

/**
 * F16: the browser fetches the lazy images of a scroll-snap track's off-screen slides at once; on the listing, 34
 * images against the 5 KB LCP image pushed LCP past S11's 2.0 s. Card carousels are deferred: their later pictures stay
 * hidden until carousel.js wakes them. No public JS runner exists, so this pins the rule and the wake in the sources.
 */
final class CardCarouselDeferTest extends TestCase
{
    public function test_a_sleeping_deferred_carousel_hides_its_later_pictures_only_while_scripting_runs(): void
    {
        $scss = self::source('assets/styles/components/_carousel.scss');

        self::assertMatchesRegularExpression(
            '/@media \(scripting: enabled\) \{\s+\[data-carousel-defer\]:not\(\[data-awake\]\) \.carousel__slide \+ \.carousel__slide picture \{\s+display: none;/',
            $scss,
        );
    }

    public function test_carousel_js_wakes_a_deferred_carousel_on_use_and_after_the_load_event(): void
    {
        $js = self::source('assets/js/carousel.js');

        self::assertStringContainsString("'carouselDefer' in carousel.dataset", $js);
        self::assertStringContainsString("carousel.dataset.awake = ''", $js);
        self::assertStringContainsString("['pointerenter', 'touchstart', 'focusin']", $js);
        self::assertStringContainsString("addEventListener('load', wakeNear", $js);
    }

    public function test_only_the_card_defers_its_carousel(): void
    {
        $deferring = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::path('templates'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'defer: true')) {
                $deferring[] = substr($file->getPathname(), \strlen(self::path('templates')) + 1);
            }
        }

        self::assertSame(['search/_card.html.twig'], $deferring);
    }

    private static function source(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 3).'/'.$relative;
    }
}
