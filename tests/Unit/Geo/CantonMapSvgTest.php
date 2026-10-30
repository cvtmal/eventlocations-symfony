<?php

declare(strict_types=1);

namespace App\Tests\Unit\Geo;

use App\Geo\ValueObject\Canton;
use PHPUnit\Framework\TestCase;

/**
 * assets/maps/cantons.svg: within budget, one path per canton, a lakes layer, CSP-ready.
 */
final class CantonMapSvgTest extends TestCase
{
    private const SVG = __DIR__.'/../../../assets/maps/cantons.svg';

    public function test_file_is_within_the_size_budget(): void
    {
        $size = filesize(self::SVG);

        self::assertNotFalse($size);
        self::assertLessThanOrEqual(40 * 1024, $size);
    }

    public function test_root_is_an_svg_with_view_box_and_no_fixed_size(): void
    {
        $markup = $this->markup();
        $root = $this->document()->documentElement;

        self::assertStringStartsWith('<svg', $markup);
        self::assertNotNull($root);
        self::assertSame('svg', $root->localName);
        self::assertNotSame('', $root->getAttribute('viewBox'));
        self::assertFalse($root->hasAttribute('width'));
        self::assertFalse($root->hasAttribute('height'));
    }

    public function test_cantons_group_has_one_path_per_canton(): void
    {
        $group = $this->groupById('cantons');
        self::assertNotNull($group);

        $ids = [];
        foreach ($group->getElementsByTagName('path') as $path) {
            $ids[] = $path->getAttribute('id');
        }
        $expected = array_map(static fn (Canton $canton): string => 'ch-'.$canton->value, Canton::cases());

        self::assertCount(26, $ids);
        self::assertEqualsCanonicalizing($expected, $ids);
    }

    public function test_lakes_layer_exists(): void
    {
        $group = $this->groupById('lakes');

        self::assertNotNull($group);
        self::assertGreaterThan(0, $group->getElementsByTagName('path')->length);
    }

    public function test_no_inline_styles_or_scripts(): void
    {
        $document = $this->document();
        $xpath = new \DOMXPath($document);

        $styles = $xpath->query('//@style');

        self::assertNotFalse($styles);
        self::assertSame(0, $styles->length);
        self::assertSame(0, $document->getElementsByTagName('style')->length);
        self::assertSame(0, $document->getElementsByTagName('script')->length);
    }

    private function markup(): string
    {
        $markup = file_get_contents(self::SVG);
        self::assertIsString($markup);

        return $markup;
    }

    private function document(): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($this->markup()));

        return $document;
    }

    private function groupById(string $id): ?\DOMElement
    {
        foreach ($this->document()->getElementsByTagName('g') as $group) {
            if ($group->getAttribute('id') === $id) {
                return $group;
            }
        }

        return null;
    }
}
