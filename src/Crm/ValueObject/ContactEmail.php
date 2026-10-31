<?php

declare(strict_types=1);

namespace App\Crm\ValueObject;

/**
 * The one normaliser of a CRM e-mail address (D6), shared with #29's inquiry contacts: trimmed and
 * lower-cased, so `crm_contacts.email` meets chk_crm_contacts_email_lower and its plain unique key
 * means "unique, case-insensitively". An empty or blank value is no address.
 */
final readonly class ContactEmail
{
    private function __construct()
    {
    }

    public static function normalise(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return '' === $email ? null : $email;
    }
}
