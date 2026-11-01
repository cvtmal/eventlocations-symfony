<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Form\EmailTemplateData;
use App\Crm\Form\EmailTemplateType;
use App\Crm\ValueObject\MergeFields;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Twig\Environment;

/**
 * The form on its own: the merge fields survive the `+intl-icu` catalogue as text, and the rules refuse each field.
 */
final class EmailTemplateTypeTest extends KernelTestCase
{
    public function test_the_help_shows_the_merge_fields_literally(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate("{% form_theme form 'admin/form/theme.html.twig' %}{{ form_row(form.bodyMarkdown) }}")
            ->render(['form' => $this->form()->createView()]);

        foreach (MergeFields::TOKENS as $token) {
            self::assertStringContainsString($token, $html);
        }
    }

    public function test_a_valid_template_builds_its_command(): void
    {
        $form = $this->form()->submit(['name' => 'Erstkontakt', 'subject' => 'Ihre Location', 'bodyMarkdown' => 'Hallo {contact.first_name}']);

        self::assertTrue($form->isValid());
        $data = $form->getData();
        self::assertInstanceOf(EmailTemplateData::class, $data);
        $command = $data->toCommand('id');
        self::assertSame('Erstkontakt', $command->name);
        self::assertSame('Ihre Location', $command->subject);
        self::assertSame('Hallo {contact.first_name}', $command->bodyMarkdown);
    }

    public function test_each_field_refuses_what_its_rule_refuses(): void
    {
        $form = $this->form()->submit(['name' => str_repeat('x', 101), 'subject' => "Betreff\nBcc: jemand@example.ch", 'bodyMarkdown' => '   ']);

        self::assertFalse($form->get('name')->isValid());
        self::assertFalse($form->get('subject')->isValid());
        self::assertFalse($form->get('bodyMarkdown')->isValid());
    }

    /**
     * @return FormInterface<mixed>
     */
    private function form(): FormInterface
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);

        return $forms->create(EmailTemplateType::class, null, ['csrf_protection' => false]);
    }
}
