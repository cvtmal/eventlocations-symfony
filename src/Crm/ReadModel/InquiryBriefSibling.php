<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * Another venue's inquiry of the same brief (F13), as the detail lists it. Staff may see the venue's name, the venues
 * never learn each other's. `linked` is false while it has no link row yet, so the inbox could not open it.
 */
final readonly class InquiryBriefSibling
{
    public function __construct(
        public string $id,
        public string $venueName,
        public bool $linked,
    ) {
    }
}
