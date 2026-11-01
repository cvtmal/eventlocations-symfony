<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No row of `crm_deal_stages` has this code. The API answers 422.
 */
final class UnknownDealStage extends DomainException
{
    private function __construct(public readonly string $stageCode)
    {
        parent::__construct(\sprintf('There is no deal stage "%s".', $stageCode));
    }

    public static function forCode(string $stageCode): self
    {
        return new self($stageCode);
    }
}
