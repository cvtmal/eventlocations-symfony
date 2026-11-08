<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\ValueObject\InquiryFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InquiryFingerprintTest extends TestCase
{
    private const SECRET = 'a secret';

    /**
     * @param array<string, mixed> $overrides
     */
    private static function inquiry(array $overrides = []): SubmitInquiry
    {
        return new SubmitInquiry(...$overrides + [
            'venueId' => '019a0000-0000-7000-8000-000000000101',
            'venueSlug' => 'schloss-seeblick',
            'locale' => 'de',
            'eventType' => 'corporate_event',
            'company' => null,
            'guests' => 120,
            'eventDate' => '2030-06-15',
            'dateFlexible' => false,
            'startTime' => null,
            'cateringWish' => null,
            'budgetChf' => null,
            'message' => "Liebes Team\n\nIst der Termin frei?",
            'firstName' => 'Anna',
            'lastName' => 'Müller',
            'email' => 'anna@example.ch',
            'phone' => '044 123 45 67',
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function sameInquiry(): iterable
    {
        yield 'upper case in the address' => [['email' => 'Anna@Example.CH']];
        yield 'windows line ends' => [['message' => "Liebes Team\r\n\r\nIst der Termin frei?"]];
        yield 'another run of spaces' => [['message' => "  Liebes  Team\n Ist der\u{A0}Termin frei? "]];
        yield 'decomposed umlaut' => [['lastName' => "Mu\u{308}ller"]];
        yield 'an old slug of a cached page' => [['venueSlug' => 'schloss-am-see']];
        yield 'another language of the page' => [['locale' => 'fr']];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('sameInquiry')]
    public function test_what_cannot_change_the_meaning_makes_no_difference(array $overrides): void
    {
        self::assertSame(self::fingerprint(), self::fingerprint($overrides));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function anotherInquiry(): iterable
    {
        yield 'another venue' => [['venueId' => '019a0000-0000-7000-8000-000000000102']];
        yield 'another event type' => [['eventType' => 'seminar']];
        yield 'a company' => [['company' => 'Muster AG']];
        yield 'a flexible date' => [['dateFlexible' => true]];
        yield 'a start time' => [['startTime' => '18:00']];
        yield 'a catering wish' => [['cateringWish' => 'in_house']];
        yield 'a budget' => [['budgetChf' => 5000]];
        yield 'one guest more' => [['guests' => 121]];
        yield 'another date' => [['eventDate' => '2030-06-16']];
        yield 'an open date' => [['eventDate' => null, 'dateFlexible' => true]];
        yield 'a corrected message' => [['message' => "Liebes Team\n\nIst der Termin noch frei?"]];
        yield 'a corrected capital' => [['message' => "liebes Team\n\nIst der Termin frei?"]];
        yield 'another first name' => [['firstName' => 'Anne']];
        yield 'another last name' => [['lastName' => 'Muller']];
        yield 'another address' => [['email' => 'anna@example.com']];
        yield 'another phone' => [['phone' => '044 123 45 68']];
        yield 'no phone' => [['phone' => null]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('anotherInquiry')]
    public function test_anything_the_guest_changed_is_another_inquiry(array $overrides): void
    {
        self::assertNotSame(self::fingerprint(), self::fingerprint($overrides));
    }

    public function test_two_fields_cannot_run_into_each_other(): void
    {
        self::assertNotSame(
            self::fingerprint(['firstName' => 'Anna', 'lastName' => 'Maria Muster']),
            self::fingerprint(['firstName' => 'Anna Maria', 'lastName' => 'Muster']),
        );
    }

    public function test_it_cannot_be_recomputed_without_the_secret(): void
    {
        $fingerprint = InquiryFingerprint::of(self::inquiry(), self::SECRET)->value;

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $fingerprint);
        self::assertNotSame($fingerprint, InquiryFingerprint::of(self::inquiry(), 'another secret')->value);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function fingerprint(array $overrides = []): string
    {
        return InquiryFingerprint::of(self::inquiry($overrides), self::SECRET)->value;
    }
}
