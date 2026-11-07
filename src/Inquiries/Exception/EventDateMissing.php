<?php

declare(strict_types=1);

namespace App\Inquiries\Exception;

use App\Shared\Exception\DomainException;

/**
 * An inquiry names a date, says the date is flexible, or both (chk_inquiries_event_date_or_flexible).
 */
final class EventDateMissing extends DomainException
{
    public static function create(): self
    {
        return new self('An inquiry needs a date or a flexible date.');
    }
}
