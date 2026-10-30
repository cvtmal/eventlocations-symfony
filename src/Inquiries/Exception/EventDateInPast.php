<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

final class EventDateInPast extends DomainException
{
    public static function for(string $date): self
    {
        return new self(\sprintf('The event date %s is in the past.', $date));
    }
}
