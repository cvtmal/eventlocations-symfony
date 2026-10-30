<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Form\ComboboxType;
use App\Admin\Form\MarkdownType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Twig\Environment;

final class FormThemeTest extends KernelTestCase
{
    public function test_described_by_joins_help_counter_error_and_a_caller_value(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('summary', TextareaType::class, [
            'help' => 'Hilfe',
            'attr' => ['data-counter-max' => 200, 'aria-describedby' => 'caller'],
        ]));
        $form->get('summary')->addError(new FormError('Zu lang'));

        $field = $this->render($form)->filter('#f_summary');

        self::assertSame('f_summary_help f_summary_counter f_summary_error caller', $field->attr('aria-describedby'));
        self::assertSame('true', $field->attr('aria-invalid'));
    }

    public function test_a_valid_field_without_help_has_no_description(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('name', TextType::class));

        $field = $this->render($form)->filter('#f_name');

        self::assertNull($field->attr('aria-describedby'));
        self::assertNull($field->attr('aria-invalid'));
    }

    public function test_a_required_field_has_the_marker_and_the_attribute(): void
    {
        $page = $this->render($this->form(static fn (FormInterface $form) => $form->add('name', TextType::class)));

        self::assertCount(1, $page->filter('label[for="f_name"] .field__required[aria-hidden="true"]'));
        self::assertNotNull($page->filter('#f_name')->attr('required'));
    }

    public function test_an_optional_field_has_neither(): void
    {
        $page = $this->render($this->form(static fn (FormInterface $form) => $form->add('street', TextType::class, ['required' => false])));

        self::assertCount(0, $page->filter('.field__required'));
        self::assertNull($page->filter('#f_street')->attr('required'));
    }

    public function test_expanded_choices_are_a_fieldset_with_a_legend(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('daylight', ChoiceType::class, [
            'expanded' => true,
            'label' => 'Tageslicht?',
            'help' => 'Hilfe',
            'choices' => ['Ja' => 'yes', 'Nein' => 'no'],
        ]));
        $form->get('daylight')->addError(new FormError('Fehlt'));

        $page = $this->render($form);

        self::assertStringContainsString('Tageslicht?', $page->filter('fieldset.field--group > legend')->text());
        self::assertSame('f_daylight_help f_daylight_error', $page->filter('fieldset')->attr('aria-describedby'));
        self::assertCount(2, $page->filter('fieldset .choice input[type="radio"]'));
        self::assertCount(0, $page->filter('fieldset .field__label-row'));
        self::assertSame('Fehlt', $page->filter('#f_daylight_error')->text());
    }

    public function test_the_chips_class_gives_chips(): void
    {
        $page = $this->render($this->form(static fn (FormInterface $form) => $form->add('setting', ChoiceType::class, [
            'expanded' => true,
            'multiple' => true,
            'attr' => ['class' => 'chips'],
            'choices' => ['Am See' => 'lake'],
        ])));

        self::assertCount(1, $page->filter('.choice-list.chips .chip-check input[type="checkbox"]'));
    }

    public function test_a_checkbox_sits_next_to_its_label(): void
    {
        $page = $this->render($this->form(static fn (FormInterface $form) => $form->add('agree', CheckboxType::class, ['label' => 'Ja'])));

        self::assertCount(1, $page->filter('.field > .choice > input[type="checkbox"]'));
        self::assertCount(1, $page->filter('.choice > label[for="f_agree"]'));
    }

    public function test_the_counter_prints_the_server_side_count(): void
    {
        $form = $this->form(
            static fn (FormInterface $form) => $form->add('summary', TextareaType::class, ['attr' => ['data-counter-max' => 200]]),
            ['summary' => str_repeat('ä', 148)],
        );

        $counter = $this->render($form)->filter('#f_summary_counter');

        self::assertSame('148 / 200', $counter->text());
        self::assertSame('f_summary', $counter->attr('data-counter-for'));
        self::assertNull($counter->attr('aria-live'));
        self::assertStringNotContainsString('field__counter--over', (string) $counter->attr('class'));
    }

    public function test_the_counter_shows_when_the_limit_is_passed(): void
    {
        $form = $this->form(
            static fn (FormInterface $form) => $form->add('summary', TextareaType::class, ['attr' => ['data-counter-max' => 3]]),
            ['summary' => 'abcd'],
        );

        self::assertStringContainsString('field__counter--over', (string) $this->render($form)->filter('#f_summary_counter')->attr('class'));
    }

    public function test_an_invalid_field_links_its_error(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('name', TextType::class));
        $form->get('name')->addError(new FormError('Fehlt'));

        $page = $this->render($form);

        self::assertSame('f_name_error', $page->filter('#f_name')->attr('aria-describedby'));
        self::assertSame('Fehlt', $page->filter('.field__error#f_name_error')->text());
    }

    public function test_a_form_level_error_is_an_alert(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('name', TextType::class));
        $form->addError(new FormError('Prüfe die Felder.'));

        self::assertSame('Prüfe die Felder.', $this->render($form, withErrors: true)->filter('.form-error[role="alert"]')->text());
    }

    public function test_money_round_trips_without_the_locale(): void
    {
        $build = static fn (FormInterface $form) => $form->add('amount', MoneyType::class, ['currency' => 'CHF', 'html5' => true]);

        $page = $this->render($this->form($build, ['amount' => 12.5]));
        self::assertSame('12.50', $page->filter('#f_amount')->attr('value'));
        self::assertSame('number', $page->filter('#f_amount')->attr('type'));
        self::assertSame('CHF', $page->filter('.field__addon .field__addon-text')->text());

        $form = $this->form($build);
        $form->submit(['amount' => '12.50']);
        self::assertSame(['amount' => 12.5], $form->getData());
    }

    public function test_a_markdown_field_has_both_tabs_around_its_textarea(): void
    {
        $page = $this->render($this->form(static fn (FormInterface $form) => $form->add('text', MarkdownType::class)));

        self::assertSame('/admin/api/markdown-preview', $page->filter('[data-markdown]')->attr('data-markdown-url'));
        self::assertCount(2, $page->filter('[data-markdown] [role="tab"]'));
        self::assertCount(1, $page->filter('#f_text_write textarea#f_text'));
        self::assertNotNull($page->filter('#f_text_preview')->attr('hidden'));
    }

    public function test_the_combobox_fallback_is_a_named_input_inside_the_mount(): void
    {
        $page = $this->render($this->form(
            static fn (FormInterface $form) => $form->add('town', ComboboxType::class, ['url' => '/admin/api/cms/towns', 'free_text' => true]),
            ['town' => '8706 Meilen'],
        ));

        $input = $page->filter('[data-island="combobox"] > input');
        self::assertSame('f[town]', $input->attr('name'));
        self::assertSame('8706 Meilen', $input->attr('value'));

        $props = json_decode((string) $page->filter('[data-island="combobox"]')->attr('data-props'), true);
        self::assertIsArray($props);
        self::assertTrue($props['freeText']);
        self::assertSame('8706 Meilen', $props['value']);
        self::assertSame('f[town]', $props['name']);
        self::assertSame('/admin/api/cms/towns', $props['url']);
    }

    public function test_the_combobox_passes_what_select_mode_needs(): void
    {
        $page = $this->render($this->form(
            static fn (FormInterface $form) => $form->add('owner', ComboboxType::class, [
                'display_value' => 'Andrea Keller',
                'static_options' => [['value' => '1', 'label' => 'Andrea Keller', 'detail' => null]],
                'help' => 'Hilfe',
            ]),
            ['owner' => '1'],
        ));

        $props = json_decode((string) $page->filter('[data-island="combobox"]')->attr('data-props'), true);
        self::assertIsArray($props);
        self::assertFalse($props['freeText']);
        self::assertSame('1', $props['value']);
        self::assertSame('Andrea Keller', $props['display']);
        self::assertSame('f_owner_help', $props['describedBy']);
        self::assertCount(1, $props['options']);
    }

    public function test_free_text_submits_exactly_what_was_typed(): void
    {
        $form = $this->form(static fn (FormInterface $form) => $form->add('town', ComboboxType::class, ['free_text' => true]));

        $form->submit(['town' => '8706 Meilen']);

        self::assertTrue($form->isValid());
        self::assertSame(['town' => '8706 Meilen'], $form->getData());
    }

    public function test_free_text_and_a_display_value_exclude_each_other(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->form(static fn (FormInterface $form) => $form->add('town', ComboboxType::class, ['free_text' => true, 'display_value' => 'x']));
    }

    public function test_the_theme_is_never_global(): void
    {
        $config = (string) file_get_contents(\dirname(__DIR__, 3).'/config/packages/twig.yaml');

        self::assertStringNotContainsString('form_themes', $config, 'The public inquiry form must not inherit the theme.');
    }

    /**
     * @param \Closure(FormInterface<mixed>): mixed $build
     * @param array<string, mixed>                  $data
     *
     * @return FormInterface<mixed>
     */
    private function form(\Closure $build, array $data = []): FormInterface
    {
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);

        $form = $factory->createNamed('f', FormType::class, $data, ['csrf_protection' => false]);
        $build($form);

        return $form;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function render(FormInterface $form, bool $withErrors = false): Crawler
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $template = "{% form_theme form 'admin/form/theme.html.twig' %}".($withErrors ? '{{ form_errors(form) }}' : '').'{{ form_rest(form) }}';

        return new Crawler('<!doctype html><html><body>'.$twig->createTemplate($template)->render(['form' => $form->createView()]).'</body></html>');
    }
}
