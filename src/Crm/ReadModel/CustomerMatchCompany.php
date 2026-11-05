<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A CRM company, of any origin, the customer import may match a sheet row to (#48 R5). `key` is
 * `geo_fold_de(name)`; `cantons` is its Eintrag's canton (0..1, #56), so a company without one has none
 * and its place is unknown (R3).
 */
final readonly class CustomerMatchCompany
{
    /**
     * @param list<string> $cantons lower-case codes, distinct, ascending
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $key,
        public ?string $website,
        public ?string $email,
        public array $cantons,
    ) {
    }
}
