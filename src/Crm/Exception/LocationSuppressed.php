<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * An imported Location (F14, `ImportLocation`) would carry an address on the suppression list (G11): an opt-out
 * or an objection is for good, so the row is not imported.
 */
final class LocationSuppressed extends DomainException
{
    private function __construct()
    {
        parent::__construct('An address of the Location is on the suppression list.');
    }

    public static function forImport(): self
    {
        return new self();
    }
}
