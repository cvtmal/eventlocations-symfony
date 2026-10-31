<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The parameters of the public `_text_input` and `_button` partials (#35). Each is rendered on its own, because no public
 * page passes `help`, `error`, `icon` or `disabled`, and `/_styleguide` shows the samples without pinning what they do.
 */
final class ComponentPartialsTest extends KernelTestCase
{
    private const string TEXT_INPUT = 'components/_text_input.html.twig';
    private const string BUTTON = 'components/_button.html.twig';
    private const array FIELD = ['id' => 'guests', 'name' => 'guests', 'label' => 'Gäste'];

    public function test_a_plain_text_input_has_no_note_and_stays_enabled(): void
    {
        $crawler = $this->render(self::TEXT_INPUT, self::FIELD);

        self::assertSame('text-input', $crawler->filter('div')->first()->attr('class'));
        self::assertCount(0, $crawler->filter('p'));
        self::assertCount(0, $crawler->filter('svg'));

        $field = $crawler->filter('input');
        self::assertNull($field->attr('aria-describedby'));
        self::assertNull($field->attr('aria-invalid'));
        self::assertNull($field->attr('disabled'));
    }

    public function test_help_describes_the_field(): void
    {
        $crawler = $this->render(self::TEXT_INPUT, self::FIELD + ['help' => 'Ungefähre Zahl']);

        self::assertSame('Ungefähre Zahl', $crawler->filter('p.text-input__help')->text());
        self::assertSame('guests-note', $crawler->filter('p.text-input__help')->attr('id'));
        self::assertSame('guests-note', $crawler->filter('input')->attr('aria-describedby'));
        self::assertNull($crawler->filter('input')->attr('aria-invalid'));
    }

    public function test_error_replaces_help_and_marks_the_field_invalid(): void
    {
        $crawler = $this->render(self::TEXT_INPUT, self::FIELD + ['help' => 'Ungefähre Zahl', 'error' => 'Bitte eine Zahl']);

        self::assertCount(0, $crawler->filter('.text-input__help'));
        self::assertSame('Bitte eine Zahl', $crawler->filter('p.text-input__error')->text());
        self::assertSame('guests-note', $crawler->filter('p.text-input__error')->attr('id'));
        self::assertSame('guests-note', $crawler->filter('input')->attr('aria-describedby'));
        self::assertSame('true', $crawler->filter('input')->attr('aria-invalid'));
    }

    public function test_icon_sets_the_modifier_and_draws_the_sprite_symbol(): void
    {
        $crawler = $this->render(self::TEXT_INPUT, self::FIELD + ['icon' => 'user']);

        self::assertSame('text-input text-input--icon', $crawler->filter('div')->first()->attr('class'));
        self::assertStringEndsWith('#user', (string) $crawler->filter('.text-input__control svg.text-input__icon use')->attr('href'));
    }

    public function test_disabled_disables_the_field(): void
    {
        $crawler = $this->render(self::TEXT_INPUT, self::FIELD + ['disabled' => true]);

        self::assertNotNull($crawler->filter('input')->attr('disabled'));
    }

    public function test_button_is_primary_at_size_md_by_default(): void
    {
        self::assertSame('btn btn--primary', $this->render(self::BUTTON, ['label' => 'Senden'])->filter('button')->attr('class'));
    }

    public function test_size_lg_adds_the_large_class_and_md_does_not(): void
    {
        self::assertSame('btn btn--primary btn--lg', $this->render(self::BUTTON, ['label' => 'Senden', 'size' => 'lg'])->filter('button')->attr('class'));
        self::assertSame('btn btn--primary', $this->render(self::BUTTON, ['label' => 'Senden', 'size' => 'md'])->filter('button')->attr('class'));
    }

    public function test_ghost_is_a_variant_of_either_element(): void
    {
        self::assertSame('btn btn--ghost', $this->render(self::BUTTON, ['label' => 'Senden', 'variant' => 'ghost'])->filter('button')->attr('class'));
        self::assertSame('btn btn--ghost btn--lg', $this->render(self::BUTTON, ['label' => 'Senden', 'variant' => 'ghost', 'size' => 'lg', 'href' => '/x'])->filter('a')->attr('class'));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context): Crawler
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler($twig->render($template, $context));
    }
}
