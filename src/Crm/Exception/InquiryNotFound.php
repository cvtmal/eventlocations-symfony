<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * No inbox row has this id (purged, or never existed). Pages answer 404.
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
