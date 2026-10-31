<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Shared\Validator\CleanText;
use Symfony\Component\Validator\Constraint;

/**
 * `PropertyConstraints`' rules for a back-office form field. `AdminTextConstraintExtension` adds
 * CleanText to every text field already, so it is left out here, or a NUL would be reported twice.
 */
final readonly class FieldRules
{
    private function __construct()
    {
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<Constraint>
     */
    public static function of(array $constraints): array
    {
        return array_values(array_filter($constraints, static fn (Constraint $constraint): bool => !$constraint instanceof CleanText));
    }
}
