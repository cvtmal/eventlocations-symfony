<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

final class GuestCountOutOfRange extends DomainException
{
    public static function for(int $guests): self
    {
        return new self(\sprintf('A guest count of %d is outside 1 to 5000.', $guests));
    }
}
