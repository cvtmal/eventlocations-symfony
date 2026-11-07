<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Form\ComboboxType;
use App\Shared\Validator\CleanText;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * `AdminTextConstraintExtension` acts under the admin firewall only, so the public inquiry form, the console
 * and a form built without a request stay as they are.
 */
final class AdminTextExtensionsTest extends KernelTestCase
{
    private const string ADMIN_PATH = '/admin/locations/1';
    private const string PUBLIC_PATH = '/eventlokale';

    protected function tearDown(): void
    {
        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        while (null !== $requests->pop()) {
            // Leave no request behind for the next test.
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{class-string<FormTypeInterface>}>
     */
    public static function textTypes(): iterable
    {
        yield 'text' => [TextType::class];
        yield 'email' => [EmailType::class];
        yield 'url' => [UrlType::class];
        yield 'textarea' => [TextareaType::class];
        yield 'combobox' => [ComboboxType::class];
    }

    /**
     * @param class-string<FormTypeInterface> $type
     */
    #[DataProvider('textTypes')]
    public function test_a_text_field_under_admin_gets_the_clean_text_rule(string $type): void
    {
        $this->pushRequest(self::ADMIN_PATH);

        self::assertTrue($this->hasCleanText($this->field($type)));
    }

    /**
     * @param class-string<FormTypeInterface> $type
     */
    #[DataProvider('textTypes')]
    public function test_a_text_field_outside_the_firewall_stays_plain(string $type): void
    {
        $this->pushRequest(self::PUBLIC_PATH);

        self::assertFalse($this->hasCleanText($this->field($type)));
    }

    /**
     * @param class-string<FormTypeInterface> $type
     */
    #[DataProvider('textTypes')]
    public function test_a_text_field_without_a_request_stays_plain(string $type): void
    {
        self::assertFalse($this->hasCleanText($this->field($type)));
    }

    public function test_hidden_and_number_fields_are_not_reached(): void
    {
        $this->pushRequest(self::ADMIN_PATH);

        self::assertFalse($this->hasCleanText($this->field(HiddenType::class)));
        self::assertFalse($this->hasCleanText($this->field(IntegerType::class)));
    }

    public function test_the_field_keeps_its_own_constraints(): void
    {
        $this->pushRequest(self::ADMIN_PATH);

        $constraints = $this->field(TextType::class, ['constraints' => new NotBlank()])->getConfig()->getOption('constraints');

        self::assertIsArray($constraints);
        self::assertCount(2, $constraints);
        self::assertInstanceOf(NotBlank::class, $constraints[0]);
        self::assertInstanceOf(CleanText::class, $constraints[1]);
    }

    public function test_a_nul_inside_a_value_makes_the_admin_form_invalid(): void
    {
        $this->pushRequest(self::ADMIN_PATH);
        $form = $this->form(TextType::class);

        $form->submit(['field' => "a\0b"]);

        self::assertFalse($form->isValid());
        $error = $form->get('field')->getErrors()->current();
        self::assertInstanceOf(FormError::class, $error);
        self::assertSame('Der Text enthält ungültige Zeichen.', $error->getMessage());
    }

    /**
     * P1 needs LF only in what a card saves. Symfony 7.4's `TextareaType::reverseTransform()` folds CRLF and
     * CR on every textarea, so the back office needs no extension of its own; this pins it across upgrades.
     */
    public function test_a_textarea_under_admin_submits_lf_only(): void
    {
        $this->pushRequest(self::ADMIN_PATH);
        $form = $this->form(TextareaType::class);

        $form->submit(['field' => "Erster Absatz\r\n\r\nZweiter\rDritter"]);

        self::assertSame("Erster Absatz\n\nZweiter\nDritter", $form->get('field')->getData());
    }

    private function pushRequest(string $path): void
    {
        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create($path));
    }

    /**
     * @param class-string<FormTypeInterface> $type
     * @param array<string, mixed>            $options
     */
    private function field(string $type, array $options = []): FormInterface
    {
        return $this->form($type, $options)->get('field');
    }

    /**
     * @param class-string<FormTypeInterface> $type
     * @param array<string, mixed>            $options
     */
    private function form(string $type, array $options = []): FormInterface
    {
        $forms = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $forms);

        return $forms->createNamedBuilder('f', FormType::class, null, ['csrf_protection' => false])
            ->add('field', $type, $options)
            ->getForm();
    }

    private function hasCleanText(FormInterface $field): bool
    {
        $constraints = $field->getConfig()->getOption('constraints');
        self::assertIsArray($constraints);

        return [] !== array_filter($constraints, static fn (mixed $constraint): bool => $constraint instanceof CleanText);
    }
}
