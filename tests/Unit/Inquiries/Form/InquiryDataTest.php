<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Form;

use App\Inquiries\Form\InquiryData;
use App\Inquiries\ValueObject\Occasion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class InquiryDataTest extends TestCase
{
    public function test_a_complete_inquiry_has_no_violations(): void
    {
        self::assertSame([], $this->violations(self::data()));
    }

    public function test_optional_fields_may_stay_empty(): void
    {
        $data = self::data();
        $data->eventDate = null;
        $data->phone = null;

        self::assertSame([], $this->violations($data));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'no occasion' => ['occasion', null, 'inquiry.occasion.required'];
        yield 'no guests' => ['guests', null, 'inquiry.guests.required'];
        yield 'zero guests' => ['guests', 0, 'inquiry.guests.range'];
        yield '5001 guests' => ['guests', 5001, 'inquiry.guests.range'];
        yield 'blank message' => ['message', '', 'inquiry.message.required'];
        yield 'long message' => ['message', str_repeat('a', 5001), 'inquiry.message.too_long'];
        yield 'NUL in the message' => ['message', "a\0b", 'inquiry.message.invalid'];
        yield 'blank first name' => ['firstName', '', 'inquiry.first_name.required'];
        yield 'long first name' => ['firstName', str_repeat('a', 101), 'inquiry.first_name.too_long'];
        yield 'header in the first name' => ['firstName', "Anna\r\nBcc: x@example.ch", 'inquiry.first_name.invalid'];
        yield 'blank name' => ['lastName', '', 'inquiry.last_name.required'];
        yield 'long name' => ['lastName', str_repeat('a', 101), 'inquiry.last_name.too_long'];
        yield 'header in the name' => ['lastName', "Muster\nBcc: x@example.ch", 'inquiry.last_name.invalid'];
        yield 'blank email' => ['email', '', 'inquiry.email.required'];
        yield 'email with two dots' => ['email', 'a..b@x.ch', 'inquiry.email.invalid'];
        yield 'email without at' => ['email', 'anna.example.ch', 'inquiry.email.invalid'];
        yield 'quoted email the CHECK refuses' => ['email', '"a b"@x.ch', 'inquiry.email.invalid'];
        yield 'umlaut in the local part' => ['email', 'müller@example.ch', 'inquiry.email.invalid'];
        yield 'em space in the local part' => ['email', "an\u{2003}na@example.ch", 'inquiry.email.invalid'];
        yield 'em space in the domain' => ['email', "anna@exam\u{2003}ple.ch", 'inquiry.email.invalid'];
        yield 'no-break space in the local part' => ['email', "an\u{A0}na@example.ch", 'inquiry.email.invalid'];
        yield 'no-break space in the domain' => ['email', "anna@exam\u{A0}ple.ch", 'inquiry.email.invalid'];
        yield 'zero-width space in the domain' => ['email', "anna@exam\u{200B}ple.ch", 'inquiry.email.invalid'];
        yield 'long email' => ['email', str_repeat('a', 64).'@'.str_repeat('b', 61).'.'.str_repeat('c', 61).'.'.str_repeat('d', 61).'.'.str_repeat('e', 7).'.ch', 'inquiry.email.too_long'];
        yield 'letters in the phone' => ['phone', '044 ABC 45 67', 'inquiry.phone.invalid'];
        yield 'short phone' => ['phone', '12345', 'inquiry.phone.invalid'];
        yield 'long phone' => ['phone', str_repeat('1', 41), 'inquiry.phone.invalid'];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_value(string $property, mixed $value, string $message): void
    {
        $data = self::data();
        $data->{$property} = $value;

        self::assertContains($message, $this->violations($data));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function validValues(): iterable
    {
        yield '1 guest' => ['guests', 1];
        yield '5000 guests' => ['guests', 5000];
        yield '5000 characters' => ['message', str_repeat('ä', 5000)];
        yield 'umlaut in the domain' => ['email', 'info@schüür.be'];
        yield 'IDN domain' => ['email', 'anna@müller.ch'];
        yield 'plus address' => ['email', 'anna+hochzeit@example.ch'];
        yield 'international phone' => ['phone', '+41 (0)44 123 45 67'];
        yield 'phone with slash' => ['phone', '044/123.45-67'];
        yield 'apostrophe in the name' => ['lastName', "D'Amico"];
    }

    #[DataProvider('validValues')]
    public function test_valid_value(string $property, mixed $value): void
    {
        $data = self::data();
        $data->{$property} = $value;

        self::assertSame([], $this->violations($data));
    }

    private static function data(): InquiryData
    {
        $data = new InquiryData();
        $data->occasion = Occasion::Wedding;
        $data->guests = 120;
        $data->eventDate = new \DateTimeImmutable('2030-06-01');
        $data->message = 'Liebes Team';
        $data->firstName = 'Anna';
        $data->lastName = 'Muster';
        $data->email = 'anna@example.ch';
        $data->phone = '044 123 45 67';

        return $data;
    }

    /**
     * @return list<string>
     */
    private function violations(InquiryData $data): array
    {
        $messages = [];
        foreach ($this->validator()->validate($data) as $violation) {
            $messages[] = (string) $violation->getMessageTemplate();
        }

        return $messages;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
