<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The fixed merge fields of a CRM mail (CRM-10). One `strtr` pass, never Twig and never `trans()`:
 * template text comes from staff, so it must not reach an engine, and `strtr` does not scan what
 * it has just written, so a value holding braces is not expanded again. An unknown token stays
 * visible, so a typo is seen in the composer rather than sent as a gap. `{company.name}`, the token's
 * name before #54, still merges as an alias of `{location.name}`, but the help no longer lists it.
 */
final readonly class MergeFields
{
    public const array TOKENS = ['{contact.first_name}', '{location.name}', '{venue.name}'];

    /** Templates stored before #54 renamed the token keep merging. */
    private const string LEGACY_COMPANY_TOKEN = '{company.name}';

    public function __construct(
        private ?string $contactFirstName,
        private ?string $companyName,
        private ?string $venueName,
    ) {
    }

    public function apply(string $text): string
    {
        $map = array_combine(self::TOKENS, [
            (string) $this->contactFirstName,
            (string) $this->companyName,
            (string) $this->venueName,
        ]);
        $map[self::LEGACY_COMPANY_TOKEN] = (string) $this->companyName;

        return strtr($text, $map);
    }
}
