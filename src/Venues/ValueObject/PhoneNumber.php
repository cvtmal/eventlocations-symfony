<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A venue's phone number as stored (E.164, `chk_venues_phone`), with its Swiss display form
 * for the phone reveal (DP-11): `+41441234567` → `044 123 45 67`, `+41800123456` →
 * `0800 123 456`. A Liechtenstein number keeps its country code (#46): `+4232345678` →
 * `+423 234 56 78`. Any other value (another country, an unexpected length) is shown as stored.
 */
final readonly class PhoneNumber
{
    private function __construct(
        private string $e164,
    ) {
    }

    public static function fromE164(string $e164): self
    {
        return new self($e164);
    }

    public function e164(): string
    {
        return $this->e164;
    }

    public function display(): string
    {
        if (1 === preg_match('/^\+423([0-9]{3})([0-9]{2})([0-9]{2})$/', $this->e164, $match)) {
            return \sprintf('+423 %s %s %s', $match[1], $match[2], $match[3]);
        }
        if (1 !== preg_match('/^\+41([0-9]{9})$/', $this->e164, $match)) {
            return $this->e164;
        }
        $national = '0'.$match[1];

        // Free and premium-rate numbers (0800, 0840, 0842, 0844, 0848, 0900, 0901, 0906) group 4-3-3.
        if ('8' === $national[1] || '9' === $national[1]) {
            return \sprintf('%s %s %s', substr($national, 0, 4), substr($national, 4, 3), substr($national, 7, 3));
        }

        return \sprintf('%s %s %s %s', substr($national, 0, 3), substr($national, 3, 3), substr($national, 6, 2), substr($national, 8, 2));
    }
}
