<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

final class BudgetOutOfRange extends DomainException
{
    public static function for(int $budgetChf): self
    {
        return new self(\sprintf('A budget of CHF %d is outside 1 to 1000000.', $budgetChf));
    }
}
