<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Twig\AdminIconExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

/**
 * Guards `assets/icons/admin-sprite.svg`, after #1's IconSpriteTest: sorted, unstyled, and complete for
 * every `admin_icon()` in templates and every icon a navigation item names.
 */
final class AdminIconSpriteTest extends TestCase
{
    private const array REQUIRED = [
        'arrow-down', 'arrow-up', 'bell', 'building-2', 'calendar', 'check', 'chevron-down', 'chevron-left', 'chevron-right', 'chevrons-up-down', 'download',
        'ellipsis', 'external-link', 'eye', 'file-text', 'grip-vertical', 'history', 'inbox', 'landmark', 'layout-dashboard', 'log-out', 'mail', 'phone', 'plus', 'search', 'shield',
        'square-check-big', 'square-kanban', 'sticky-note', 'type', 'upload', 'users', 'x',
    ];

    public function test_ids_are_unique_and_sorted(): void
    {
        $ids = self::ids();
        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertSame(array_values(array_unique($ids)), $ids);
        self::assertSame($sorted, $ids);
    }

    public function test_the_sprite_holds_exactly_the_required_icons(): void
    {
        self::assertSame(self::REQUIRED, self::ids());
    }

    public function test_symbols_carry_no_presentation_attributes(): void
    {
        foreach (self::dom()->getElementsByTagName('*') as $element) {
            foreach (['stroke', 'fill', 'stroke-width', 'width', 'height'] as $attribute) {
                if ('symbol' === $element->tagName || 'svg' === $element->tagName || !\in_array($attribute, ['width', 'height'], true)) {
                    self::assertFalse($element->hasAttribute($attribute), \sprintf('<%s> has no "%s".', $element->tagName, $attribute));
                }
            }
        }
    }

    public function test_every_icon_used_in_the_back_office_exists(): void
    {
        $ids = self::ids();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/templates/admin', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            preg_match_all("/admin_icon\\(\\s*'([a-z0-9-]+)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                self::assertContains($name, $ids, $file->getPathname());
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_every_icon_used_in_the_vue_kit_exists(): void
    {
        $ids = self::ids();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/admin-ui/src', \FilesystemIterator::SKIP_DOTS));
        $checked = 0;

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            preg_match_all('/<(?:UiIcon|UiButton|UiEmpty)\b[^>]*?\s(?:name|icon)="([a-z0-9-]+)"/s', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                self::assertContains($name, $ids, $file->getPathname());
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function test_the_function_renders_a_decorative_icon_or_a_labelled_one(): void
    {
        $extension = new AdminIconExtension(new Packages(new PathPackage('/assets', new EmptyVersionStrategy())));

        self::assertSame('<svg class="icon" aria-hidden="true"><use href="/assets/icons/admin-sprite.svg#plus"></use></svg>', $extension->icon('plus'));
        self::assertSame('<svg class="icon" role="img" aria-label="Neu &amp; mehr"><use href="/assets/icons/admin-sprite.svg#plus"></use></svg>', $extension->icon('plus', 'Neu & mehr'));
    }

    public function test_an_invalid_name_is_refused(): void
    {
        $extension = new AdminIconExtension(new Packages(new PathPackage('/assets', new EmptyVersionStrategy())));

        $this->expectException(\InvalidArgumentException::class);

        $extension->icon('../evil');
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
        self::assertTrue($dom->load(self::root().'/assets/icons/admin-sprite.svg'));

        return $dom;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
