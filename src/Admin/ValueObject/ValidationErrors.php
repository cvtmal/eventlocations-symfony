<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * The body of a JSON 422 under /admin/api: `{"errors": {"field": ["message"]}}`, with form-level errors
 * under the field `""`.
 */
final readonly class ValidationErrors
{
    /**
     * @param array<string, list<string>> $errors
     */
    private function __construct(
        private array $errors,
    ) {
    }

    public static function fromViolations(ConstraintViolationListInterface $violations): self
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return new self($errors);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public static function fromForm(FormInterface $form): self
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            \assert($error instanceof FormError);
            $origin = $error->getOrigin();
            $field = null === $origin || $origin === $form ? '' : $origin->getName();
            $errors[$field][] = $error->getMessage();
        }

        return new self($errors);
    }

    /**
     * @return array{errors: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['errors' => $this->errors];
    }
}
