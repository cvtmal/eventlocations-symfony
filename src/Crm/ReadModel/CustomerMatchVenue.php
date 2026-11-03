<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A venue the customer import may match a sheet row to (#48 R5), with the company it already belongs to.
 * `key` is `geo_fold_de(name)`, folded in SQL like the sheet's names.
 */
final readonly class CustomerMatchVenue
{
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
        public ?string $cantonCode,
        public ?string $website,
        public ?string $companyId,
        public ?string $companyName,
    ) {
    }
}
