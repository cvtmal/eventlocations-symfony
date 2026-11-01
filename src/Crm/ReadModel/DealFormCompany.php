<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * The company of a new deal, with what the form offers from it (N5).
 */
final readonly class DealFormCompany
{
    /**
     * @param list<DealFormOption> $venues
     * @param list<DealFormOption> $contacts
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $venues,
        public array $contacts,
    ) {
    }
}
