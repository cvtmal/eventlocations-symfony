<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\ValueObject\ValidationErrors;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

final class ValidationErrorsTest extends TestCase
{
    public function test_violations_are_grouped_by_field(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Zu kurz', null, [], null, 'name', ''),
            new ConstraintViolation('Zu einfach', null, [], null, 'name', ''),
            new ConstraintViolation('Fehlt', null, [], null, 'email', ''),
        ]);

        self::assertSame(
            ['errors' => ['name' => ['Zu kurz', 'Zu einfach'], 'email' => ['Fehlt']]],
            ValidationErrors::fromViolations($violations)->toArray(),
        );
    }

    public function test_form_errors_keep_their_field_and_form_level_ones_go_under_the_empty_key(): void
    {
        $form = Forms::createFormFactory()->createBuilder()->add('name', TextType::class)->getForm();
        $form->submit(['name' => 'x']);
        $form->addError(new FormError('Das Formular passt nicht'));
        $form->get('name')->addError(new FormError('Zu kurz'));

        self::assertSame(
            ['errors' => ['' => ['Das Formular passt nicht'], 'name' => ['Zu kurz']]],
            ValidationErrors::fromForm($form)->toArray(),
        );
    }
}
