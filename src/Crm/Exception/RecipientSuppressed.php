<?php

declare(strict_types=1);

namespace App\Crm\Exception;

use App\Shared\Exception\DomainException;

/**
 * A CRM mail would reach a Location or an address on the suppression list (G11). The message names the field, never
 * the address.
 */
final class RecipientSuppressed extends DomainException
{
    private function __construct(public readonly string $field)
    {
        parent::__construct(\sprintf('The %s of this mail is on the suppression list.', $field));
    }

    /** The Location itself or the address the mail goes to. */
    public static function forRecipient(): self
    {
        return new self('contactId');
    }

    public static function forCc(): self
    {
        return new self('cc');
    }
}
