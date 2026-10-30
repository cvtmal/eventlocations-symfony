<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

use App\Inquiries\Command\SubmitInquiry;

/**
 * Recognises a repeat of one inquiry: the venue plus everything the guest typed. It is not a key
 * to the guest. Keyed with the app secret, so nobody can test a guessed address or message
 * against it, and over the whole payload per venue, so it neither finds a person nor links their
 * inquiries to two venues. It lives on the row, so it goes with the row (LEG-2).
 *
 * Only what cannot change the meaning is normalised: Unicode form, runs of whitespace, the case
 * of the address. A corrected typo is a new inquiry. The slug and the locale are left out: a
 * cached page can carry an old slug, and neither is something the guest typed.
 */
final readonly class InquiryFingerprint
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function of(SubmitInquiry $inquiry, string $secret): self
    {
        $payload = json_encode([
            $inquiry->venueId,
            $inquiry->occasion,
            $inquiry->guests,
            $inquiry->eventDate,
            self::text($inquiry->message),
            self::text($inquiry->firstName),
            self::text($inquiry->lastName),
            mb_strtolower(self::text($inquiry->email)),
            self::text($inquiry->phone ?? ''),
        ], \JSON_THROW_ON_ERROR);

        return new self(hash_hmac('sha256', $payload, $secret));
    }

    private static function text(string $value): string
    {
        $text = \Normalizer::normalize($value);
        $text = \is_string($text) ? $text : $value;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
