<?php

declare(strict_types=1);

namespace App\Shared\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class CleanTextValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CleanText) {
            throw new UnexpectedTypeException($constraint, CleanText::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!\is_scalar($value) && !$value instanceof \Stringable) {
            throw new UnexpectedValueException($value, 'string');
        }

        $value = (string) $value;
        if (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
            // No {{ value }}: the message formatter cannot take invalid UTF-8.
            $this->context->buildViolation($constraint->message)
                ->setCode(CleanText::INVALID)
                ->addViolation();
        }
    }
}
