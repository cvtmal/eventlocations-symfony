<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Shared\Twig\IconExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\PackageInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

final class IconExtensionTest extends TestCase
{
    public function test_renders_a_decorative_icon_hidden_from_assistive_technology(): void
    {
        self::assertSame(
            '<svg class="icon" aria-hidden="true"><use href="icons/sprite.svg#heart"></use></svg>',
            self::extension()->icon('heart'),
        );
    }

    public function test_label_makes_the_icon_an_image_with_an_accessible_name(): void
    {
        $html = self::extension()->icon('heart', ['label' => 'Merkliste']);

        self::assertSame('<svg class="icon" role="img" aria-label="Merkliste"><use href="icons/sprite.svg#heart"></use></svg>', $html);
        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function test_extra_class_is_appended(): void
    {
        self::assertSame(
            '<svg class="icon icon--sm" aria-hidden="true"><use href="icons/sprite.svg#x"></use></svg>',
            self::extension()->icon('x', ['class' => ' icon--sm ']),
        );
    }

    public function test_label_and_class_are_escaped(): void
    {
        $html = self::extension()->icon('x', ['label' => '"<x>', 'class' => '"<x>']);

        self::assertStringContainsString('aria-label="&quot;&lt;x&gt;"', $html);
        self::assertStringContainsString('class="icon &quot;&lt;x&gt;"', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'uppercase' => ['Heart'];
        yield 'quote' => ['x"y'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_name_is_rejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::extension()->icon($name);
    }

    public function test_sprite_url_is_resolved_once(): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->expects(self::once())->method('getUrl')->with('icons/sprite.svg')->willReturn('/assets/icons/sprite-abc.svg');
        $extension = new IconExtension(new Packages($package));

        $extension->icon('heart');
        $html = $extension->icon('x');

        self::assertStringContainsString('href="/assets/icons/sprite-abc.svg#x"', $html);
    }

    private static function extension(): IconExtension
    {
        return new IconExtension(new Packages(new Package(new EmptyVersionStrategy())));
    }
}
