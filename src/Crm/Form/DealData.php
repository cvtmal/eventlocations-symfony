<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\Command\CreateDeal;
use App\Crm\ValueObject\TaskDueDate;
use App\Venues\ValueObject\Tier;

/**
 * "Deal erfassen" (CRM-7), the second step: the deal of one company (N5). The rules are `PropertyConstraints`', set by
 * `DealType`. The days stay strings, the value the inline edit sends, so one shape rule covers both.
 */
final class DealData
{
    public ?string $name = null;
    public ?string $venue = null;
    public ?Tier $tier = null;
    public ?int $amount = null;
    public ?string $contractStart = null;
    public ?string $contractEnd = null;
    public ?string $expectedClose = null;
    public ?string $owner = null;

    public function toCommand(string $id, string $companyId): CreateDeal
    {
        return new CreateDeal(
            $id,
            (string) $this->name,
            $companyId,
            $this->venue,
            $this->tier,
            $this->amount,
            TaskDueDate::parse($this->contractStart),
            TaskDueDate::parse($this->contractEnd),
            TaskDueDate::parse($this->expectedClose),
            $this->owner,
        );
    }
}
