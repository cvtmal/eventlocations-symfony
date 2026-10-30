<?php

declare(strict_types=1);

namespace App\Shared\Validator;

use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses text Postgres cannot store as typed: a NUL, which PDO silently truncates the value at, and invalid
 * UTF-8, which the database rejects with SQLSTATE 22021 (a 500 if it got that far). Null and '' pass.
 *
 * Not a `Compound` of `Regex('/\x00/u', match: false)`, which would refuse both too: `Regex` hands the
 * value to its message as `{{ value }}`, and the ICU formatter of an `+intl-icu` catalogue throws on invalid
 * UTF-8 in any parameter, so the error page would be a 500 after all. `CleanTextValidator` names no value.
 *
 * `Admin\Form\AdminTextConstraintExtension` adds it to every text field of the back office; a JSON DTO
 * (#25) puts the attribute on its string properties directly.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class CleanText extends Constraint
{
    public const string INVALID = '4c7d5e0a-8f3b-4b1e-9a62-2f0d6c1e7a11';

    protected const array ERROR_NAMES = [self::INVALID => 'INVALID_TEXT'];

    public string $message = 'shared.text.invalid';

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);

        $this->message = $message ?? $this->message;
    }
}
