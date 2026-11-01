<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * What the deal list narrows by (D12): `q` a deal's or company's name, `stage` a stage code, `owner` an admin user id
 * or `CompanyListFilter::OWNER_NONE`, `month` `2026-11` of the expected close. The board takes `owner` and `month`.
 */
final readonly class DealFilter
{
    public function __construct(
        public ?string $q = null,
        public ?string $stage = null,
        public ?string $owner = null,
        public ?string $month = null,
    ) {
    }
}
