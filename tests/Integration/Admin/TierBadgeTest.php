<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The partial holds a hash so Admin stays free of Venues; this test may know both.
 */
final class TierBadgeTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{Tier, string}>
     */
    public static function tones(): iterable
    {
        yield 'Beliebte Location' => [Tier::BeliebteLocation, 'pink'];
        yield 'Empfehlung' => [Tier::Empfehlung, 'amber'];
        yield 'Top Premium' => [Tier::TopPremium, 'blue'];
        yield 'Premium' => [Tier::Premium, 'green'];
        yield 'Standard' => [Tier::Basic, 'grey'];
    }

    #[DataProvider('tones')]
    public function test_each_tier_has_the_tone_of_the_design(Tier $tier, string $tone): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('admin/components/_tier_badge.html.twig', ['tier' => $tier->value, 'label' => 'x']);

        self::assertStringContainsString('pill--'.$tone, $html);
    }

    public function test_the_list_covers_every_tier(): void
    {
        self::assertCount(\count(Tier::cases()), iterator_to_array(self::tones()));
    }
}
