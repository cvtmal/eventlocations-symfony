<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * The field changed after the record page was loaded (D11): the `expected` value sent with the
 * save no longer matches the stored one. Nothing was written; the API answers 409.
 */
final class StaleProperty extends DomainException
{
    private function __construct(public readonly string $field)
    {
        parent::__construct(\sprintf('The field "%s" was changed after the page was loaded.', $field));
    }

    public static function forField(string $field): self
    {
        return new self($field);
    }
}
