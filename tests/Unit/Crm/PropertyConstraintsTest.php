<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Form\PropertyConstraints;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\ContactProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Sequentially;
use Symfony\Component\Validator\Validation;

/**
 * The input rules of every inline-edited field (D10, P1), on the canonical value: one accepted and
 * one refused value per case, and a NUL refused wherever a string is free text.
 */
final class PropertyConstraintsTest extends TestCase
{
    private const string UUID = '0192f0a4-7c3e-7d1a-9b2c-3d4e5f607182';

    /**
     * @return iterable<string, array{CompanyProperty, ?string, ?string}>
     */
    public static function companyCases(): iterable
    {
        yield 'name' => [CompanyProperty::Name, 'Seeblick Events', null];
        yield 'legal_name' => [CompanyProperty::LegalName, 'Seeblick Events GmbH', str_repeat('x', 201)];
        yield 'website' => [CompanyProperty::Website, 'https://seeblick.ch', 'seeblick.ch'];
        yield 'phone' => [CompanyProperty::Phone, '+41 44 000 00 00', 'ruf mich an'];
        yield 'email' => [CompanyProperty::Email, 'info@seeblick.ch', 'info@seeblick'];
        yield 'address' => [CompanyProperty::Address, 'Seestrasse 12, 8706 Meilen', str_repeat('x', 301)];
        yield 'stage' => [CompanyProperty::Stage, 'customer', 'partner'];
        yield 'owner' => [CompanyProperty::Owner, self::UUID, 'andrea'];
        yield 'notes' => [CompanyProperty::Notes, "Zwei Zeilen\nNotiz", str_repeat('x', PropertyConstraints::NOTES_MAX + 1)];
    }

    /**
     * @return iterable<string, array{ContactProperty, ?string, ?string}>
     */
    public static function contactCases(): iterable
    {
        yield 'first_name' => [ContactProperty::FirstName, 'Anna', str_repeat('x', 151)];
        yield 'last_name' => [ContactProperty::LastName, 'Muster', null];
        yield 'email' => [ContactProperty::Email, 'anna@example.ch', 'anna müller@example.ch'];
        yield 'phone' => [ContactProperty::Phone, '079 123 45 67', '+'];
        yield 'position' => [ContactProperty::Position, 'Inhaberin', str_repeat('x', 151)];
        yield 'kind' => [ContactProperty::Kind, 'venue_contact', 'friend'];
        yield 'company' => [ContactProperty::Company, self::UUID, '42'];
        yield 'owner' => [ContactProperty::Owner, null, 'not-a-uuid'];
    }

    #[DataProvider('companyCases')]
    public function test_a_company_field_accepts_and_refuses(CompanyProperty $property, ?string $valid, ?string $invalid): void
    {
        self::assertSame(0, $this->violations(PropertyConstraints::company($property), $valid), $property->value.' accepts');
        self::assertGreaterThan(0, $this->violations(PropertyConstraints::company($property), $invalid), $property->value.' refuses');
    }

    #[DataProvider('contactCases')]
    public function test_a_contact_field_accepts_and_refuses(ContactProperty $property, ?string $valid, ?string $invalid): void
    {
        self::assertSame(0, $this->violations(PropertyConstraints::contact($property), $valid), $property->value.' accepts');
        self::assertGreaterThan(0, $this->violations(PropertyConstraints::contact($property), $invalid), $property->value.' refuses');
    }

    public function test_every_free_text_field_refuses_a_nul_and_invalid_utf8(): void
    {
        $fields = [
            ...array_map(static fn (CompanyProperty $p): array => PropertyConstraints::company($p), [CompanyProperty::Name, CompanyProperty::LegalName, CompanyProperty::Website, CompanyProperty::Phone, CompanyProperty::Email, CompanyProperty::Address, CompanyProperty::Notes]),
            ...array_map(static fn (ContactProperty $p): array => PropertyConstraints::contact($p), [ContactProperty::FirstName, ContactProperty::LastName, ContactProperty::Email, ContactProperty::Phone, ContactProperty::Position]),
        ];

        foreach ($fields as $index => $constraints) {
            self::assertGreaterThan(0, $this->violations($constraints, "a\0b"), 'NUL, field '.$index);
            self::assertGreaterThan(0, $this->violations($constraints, "a\xFFb"), 'invalid UTF-8, field '.$index);
        }
    }

    public function test_a_nullable_field_accepts_null_and_a_required_one_does_not(): void
    {
        foreach (CompanyProperty::cases() as $property) {
            self::assertSame($property->isNullable(), 0 === $this->violations(PropertyConstraints::company($property), null), $property->value);
        }
        foreach (ContactProperty::cases() as $property) {
            self::assertSame($property->isNullable(), 0 === $this->violations(PropertyConstraints::contact($property), null), $property->value);
        }
    }

    /**
     * @param list<\Symfony\Component\Validator\Constraint> $constraints
     */
    private function violations(array $constraints, ?string $value): int
    {
        return \count(Validation::createValidator()->validate($value, new Sequentially($constraints)));
    }
}
