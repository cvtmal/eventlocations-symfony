<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The fixed merge fields of a CRM mail (CRM-10). One `strtr` pass, never Twig and never `trans()`:
 * template text comes from staff, so it must not reach an engine, and `strtr` does not scan what
 * it has just written, so a value holding braces is not expanded again. An unknown token stays
 * visible, so a typo is seen in the composer rather than sent as a gap.
 */
final readonly class MergeFields
{
    public const array TOKENS = ['{contact.first_name}', '{company.name}', '{venue.name}'];

    public function __construct(
        private ?string $contactFirstName,
        private ?string $companyName,
        private ?string $venueName,
    ) {
    }

    public function apply(string $text): string
    {
        return strtr($text, array_combine(self::TOKENS, [
            (string) $this->contactFirstName,
            (string) $this->companyName,
            (string) $this->venueName,
        ]));
    }
}
