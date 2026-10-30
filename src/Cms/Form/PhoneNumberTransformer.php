<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\ValueObject\PhoneNumber;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * The contact card's phone: stored as E.164 (`chk_venues_phone`), shown and typed the Swiss way. A Swiss
 * national number (`044 123 45 67`, `0800 123 456`) becomes `+41…`, `00` becomes `+`, and a `(0)` after the
 * country code is dropped. Anything that is not E.164 then is a field error (the field's `invalid_message`).
 *
 * @implements DataTransformerInterface<string, string>
 */
final class PhoneNumberTransformer implements DataTransformerInterface
{
    private const string E164 = '/\A\+[1-9][0-9]{6,14}\z/';

    public function transform(mixed $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return PhoneNumber::fromE164($value)->display();
    }

    public function reverseTransform(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $number = str_replace(['(0)', ' ', '.', '-', '/', '(', ')'], '', $value);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        } elseif (str_starts_with($number, '0')) {
            $number = '+41'.substr($number, 1);
        }

        if (1 !== preg_match(self::E164, $number)) {
            throw new TransformationFailedException('Not a phone number in E.164 form.');
        }

        return $number;
    }
}
