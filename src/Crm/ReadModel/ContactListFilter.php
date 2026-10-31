<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ContactKind;

/**
 * What the contact list narrows by (CRM-6, D20): `q` a name or an e-mail address, `kind`, `owner` an
 * admin user id or `CompanyListFilter::OWNER_NONE`.
 */
final readonly class ContactListFilter
{
    public function __construct(
        public ?string $q = null,
        public ?ContactKind $kind = null,
        public ?string $owner = null,
    ) {
    }
}
