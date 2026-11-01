<?php

declare(strict_types=1);

namespace App\Tests\Integration\Favorites;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The accessible name of `favorites/_merken_button` (#37, D20). Rendered on its own, so the two variants are compared
 * without a page around them. A card's name must tell its button from 47 others; a detail page's must contain the
 * visible words (axe `label-content-name-mismatch`).
 */
final class MerkenButtonPartialTest extends KernelTestCase
{
    public function test_a_card_button_is_named_after_its_venue(): void
    {
        $html = $this->render(['variant' => 'card']);

        self::assertStringContainsString('aria-pressed="false" aria-label="Schloss Seeburg merken" data-merken="7" data-merken-name="Schloss Seeburg"', $html);
    }

    public function test_a_detail_button_is_named_by_its_text(): void
    {
        $button = (new Crawler($this->render(['variant' => 'detail', 'label' => 'Location merken'])))->filter('button');

        self::assertNull($button->attr('aria-label'));
        self::assertSame('Location merken', $button->text());
    }

    /**
     * @param array<string, string> $context
     */
    #[DataProvider('variants')]
    public function test_both_variants_keep_the_hooks_of_the_script_and_the_form(array $context): void
    {
        $crawler = new Crawler($this->render($context));
        $button = $crawler->filter('form.merken button.merken__button');

        self::assertSame('/merkliste/7', $crawler->filter('form.merken')->attr('action'));
        self::assertSame('7', $button->attr('data-merken'));
        self::assertSame('Schloss Seeburg', $button->attr('data-merken-name'));
        self::assertSame('false', $button->attr('aria-pressed'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function variants(): iterable
    {
        yield 'card' => [['variant' => 'card']];
        yield 'detail' => [['variant' => 'detail', 'label' => 'Location merken']];
    }

    /**
     * @param array<string, string> $context
     */
    private function render(array $context): string
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('favorites/_merken_button.html.twig', ['number' => 7, 'name' => 'Schloss Seeburg'] + $context);
    }
}
