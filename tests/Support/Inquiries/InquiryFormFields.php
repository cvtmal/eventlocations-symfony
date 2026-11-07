<?php

declare(strict_types=1);

namespace App\Tests\Support\Inquiries;

use App\Inquiries\Form\FillTimeToken;
use Symfony\Component\Clock\MockClock;

/**
 * A valid POST body of the inquiry form: the fill-time token is a minute old, and `_token` is
 * the constant of the stateless CSRF check, whose real test is the `Sec-Fetch-Site` header.
 */
final class InquiryFormFields
{
    public const TEST_SECRET = '$ecretf0rt3st';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{inquiry: array<string, mixed>}
     */
    public static function valid(array $overrides = []): array
    {
        return ['inquiry' => array_filter(array_replace([
            'eventType' => 'corporate_event',
            'guests' => '120',
            'eventDate' => '',
            'dateFlexible' => '1',
            'message' => "Liebes Team\n\nIst der Termin noch frei?",
            'firstName' => 'Anna',
            'lastName' => 'Muster',
            'email' => 'anna@example.ch',
            'phone' => '044 123 45 67',
            'website' => '',
            'started' => self::tokenFrom('-60 seconds'),
            '_token' => 'csrf-token',
        ], $overrides), static fn (mixed $value): bool => null !== $value)];
    }

    public static function tokenFrom(string $time): string
    {
        return (new FillTimeToken(new MockClock($time), self::TEST_SECRET))->issue();
    }
}
