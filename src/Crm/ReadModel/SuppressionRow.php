<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\SuppressionReason;

/**
 * One row of the suppression list: a Location (id and name) or an address.
 */
final readonly class SuppressionRow
{
    public function __construct(
        public string $id,
        public ?string $companyId,
        public ?string $companyName,
        public ?string $email,
        public SuppressionReason $reason,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
