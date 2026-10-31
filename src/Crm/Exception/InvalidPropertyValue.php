<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A property value the handler cannot apply: a required field emptied, an unknown stage or kind,
 * an unknown or inactive owner, an unknown company. The API's constraints refuse these first;
 * this is the handler's own guard. The message never quotes the value. The API answers 422 on `value`.
 */
final class InvalidPropertyValue extends DomainException
{
    private function __construct(public readonly string $field, private readonly bool $required, string $reason)
    {
        parent::__construct(\sprintf('The value of "%s" is invalid: %s.', $field, $reason));
    }

    public static function required(string $field): self
    {
        return new self($field, true, 'it may not be empty');
    }

    public static function unknown(string $field): self
    {
        return new self($field, false, 'no such choice');
    }

    /** Whether the field was emptied (else the value names no known choice). */
    public function isRequired(): bool
    {
        return $this->required;
    }
}
