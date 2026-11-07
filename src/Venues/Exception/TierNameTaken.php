<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * Another tier already has this name (`uk_tiers_name`, an exact match). The staff screen checks the name before it
 * dispatches, so this is what a save meets when the name was taken in between; the form answers 422 on its name
 * field either way. The message never quotes the name.
 */
final class TierNameTaken extends DomainException
{
    private function __construct()
    {
        parent::__construct('Another tier has this name.');
    }

    public static function create(): self
    {
        return new self();
    }
}
