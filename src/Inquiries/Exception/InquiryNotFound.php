<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

/**
 * No inquiry has this id, for example because the retention purge deleted it.
 */
final class InquiryNotFound extends DomainException
{
    private function __construct(public readonly string $inquiryId)
    {
        parent::__construct(\sprintf('There is no inquiry "%s".', $inquiryId));
    }

    public static function forId(string $inquiryId): self
    {
        return new self($inquiryId);
    }
}
