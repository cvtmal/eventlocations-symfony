<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;

/**
 * Guards `assets/icons/sprite.svg` (#1, D12): well-formed, sorted, unstyled, and complete for every template.
 */
final class IconSpriteTest extends TestCase
{
    private const array REQUIRED = [
        // UI
        'calendar', 'camera', 'car', 'check', 'chevron-down', 'chevron-left', 'chevron-right', 'file-text', 'globe', 'heart', 'house', 'info', 'mail',
        'map-pin', 'maximize', 'menu', 'phone', 'plane', 'printer', 'search', 'share-2', 'thumbs-up', 'train-front', 'user', 'x',
        // Lage
        'building-2', 'mountain-snow', 'sailboat', 'trees', 'waves',
        // Highlights
        'binoculars', 'coins', 'landmark', 'music', 'route', 'smile', 'sofa', 'sparkles', 'utensils',
        // Home categories
        'beer', 'castle', 'grape', 'hotel', 'warehouse',
    ];

    private const array PRESENTATION_ATTRIBUTES = ['stroke', 'fill', 'stroke-width'];

    public function test_sprite_is_well_formed_and_ids_are_unique_and_sorted(): void
    {
        $ids = self::ids();
        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertSame(array_values(array_unique($ids)), $ids, 'Symbol ids are unique.');
        self::assertSame($sorted, $ids, 'Symbols are sorted by id.');
    }

    public function test_sprite_contains_every_required_icon(): void
    {
        $missing = array_diff(self::REQUIRED, self::ids());

        self::assertSame([], array_values($missing));
        self::assertCount(44, self::REQUIRED);
    }

    public function test_symbols_carry_no_presentation_attributes(): void
    {
        $dom = self::dom();
        $root = $dom->documentElement;
        \assert($root instanceof \DOMElement);

        foreach (['width', 'height', ...self::PRESENTATION_ATTRIBUTES] as $attribute) {
            self::assertFalse($root->hasAttribute($attribute), \sprintf('The sprite root has no "%s".', $attribute));
        }

        foreach ($root->getElementsByTagName('*') as $element) {
            foreach (self::PRESENTATION_ATTRIBUTES as $attribute) {
                self::assertFalse($element->hasAttribute($attribute), \sprintf('<%s> has no "%s"; .icon sets it.', $element->tagName, $attribute));
            }
        }

        foreach ($root->getElementsByTagName('symbol') as $symbol) {
            self::assertSame('0 0 24 24', $symbol->getAttribute('viewBox'));
            self::assertFalse($symbol->hasAttribute('width'));
            self::assertFalse($symbol->hasAttribute('height'));
        }
    }

    public function test_every_icon_used_in_templates_exists_in_the_sprite(): void
    {
        $ids = self::ids();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/templates', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if (!str_ends_with($file->getFilename(), '.twig')) {
                continue;
            }
            preg_match_all("/\\bicon\\(\\s*'([a-z0-9-]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                self::assertContains($name, $ids, \sprintf('%s uses icon "%s", which is not in the sprite.', $file->getPathname(), $name));
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    /**
     * @return list<string>
     */
    private static function ids(): array
    {
        $ids = [];
        foreach (self::dom()->getElementsByTagName('symbol') as $symbol) {
            $ids[] = $symbol->getAttribute('id');
        }

        return $ids;
    }

    private static function dom(): \DOMDocument
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->load(self::root().'/assets/icons/sprite.svg'), 'The sprite parses as XML.');

        return $dom;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
