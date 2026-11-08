<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Venues\ValueObject\TierLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The partial takes plain values (a label and whether the tier ranks above the unboosted ones) so Admin stays free
 * of Venues; this test may know both (F12: tiers are data, so there is no tone per tier code).
 */
final class TierBadgeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function tones(): iterable
    {
        yield 'a promoted tier' => [true, 'green'];
        yield 'a tier without a boost' => [false, 'grey'];
    }

    #[DataProvider('tones')]
    public function test_a_promoted_tier_is_green_and_any_other_grey(bool $promoted, string $tone): void
    {
        $html = $this->render('Etwas', $promoted);

        self::assertStringContainsString('pill--'.$tone, $html);
        self::assertStringContainsString('>Etwas<', $html);
    }

    public function test_it_takes_a_tier_label_as_the_read_models_build_it(): void
    {
        $free = new TierLabel('free', 'Gratis', false);
        $pro = new TierLabel('pro', 'Pro', true);

        self::assertStringContainsString('pill--grey', $this->render($free->name, $free->promoted));
        self::assertStringContainsString('>Gratis<', $this->render($free->name, $free->promoted));
        self::assertStringContainsString('pill--green', $this->render($pro->name, $pro->promoted));
        self::assertStringContainsString('>Pro<', $this->render($pro->name, $pro->promoted));
    }

    public function test_the_name_is_escaped(): void
    {
        $html = $this->render('<b>Plan</b>', false);

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;b&gt;Plan&lt;/b&gt;', $html);
    }

    private function render(string $label, bool $promoted): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('admin/components/_tier_badge.html.twig', ['label' => $label, 'promoted' => $promoted]);
    }
}
