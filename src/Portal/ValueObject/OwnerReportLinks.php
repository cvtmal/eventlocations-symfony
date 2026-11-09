<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * The absolute links of one monthly report mail (G10): the entry's `/konto` page (through the login, which returns
 * the reader to it), the suggestion's tab, the plan card for «Pro anfragen», and the venue's opt-out link.
 */
final readonly class OwnerReportLinks
{
    public function __construct(
        public string $edit,
        public string $suggestion,
        public string $pro,
        public string $optOut,
    ) {
    }
}
