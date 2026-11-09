<?php

declare(strict_types=1);

namespace App\Shared\Mailer;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * The Reply-To of a staff notice about something a person did (a manager's access request, a new location, a content
 * report), so staff answer that person with one click. The address is visitor input: the forms validate it, and
 * `Address` checks it again (RFC syntax and no control character, so no line break reaches a header) and strips
 * line breaks from the name. An empty or invalid address gives null: the notice still goes out, with the mailer's
 * usual Reply-To or none.
 */
final class ReplyTo
{
    public static function person(?string $email, ?string $name = null): ?Address
    {
        $email = trim($email ?? '');
        if ('' === $email) {
            return null;
        }

        try {
            return new Address($email, trim($name ?? ''));
        } catch (RfcComplianceException|InvalidArgumentException) { // not an addr-spec, or a control character
            return null;
        }
    }
}
