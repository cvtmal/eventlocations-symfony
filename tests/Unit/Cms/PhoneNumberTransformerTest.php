<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\Form\PhoneNumberTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class PhoneNumberTransformerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> typed, stored
     */
    public static function typed(): iterable
    {
        yield 'Swiss national' => ['044 123 45 67', '+41441234567'];
        yield 'international with spaces' => ['+41 44 123 45 67', '+41441234567'];
        yield 'double zero' => ['0041 44 123 45 67', '+41441234567'];
        yield 'zero in brackets' => ['+41 (0)44 123 45 67', '+41441234567'];
        yield 'separators' => ['044/123.45-67', '+41441234567'];
        yield 'free number' => ['0800 123 456', '+41800123456'];
        yield 'mobile' => ['079 123 45 67', '+41791234567'];
        yield 'German number' => ['+49 30 1234567', '+49301234567'];
        yield 'already E.164' => ['+41441234567', '+41441234567'];
    }

    #[DataProvider('typed')]
    public function test_a_typed_number_becomes_e164(string $typed, string $stored): void
    {
        self::assertSame($stored, new PhoneNumberTransformer()->reverseTransform($typed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'letters' => ['Telefon'];
        yield 'too short' => ['044 12'];
        yield 'too long' => ['+41 44 123 45 67 89 01 23'];
        yield 'plus zero' => ['+0441234567'];
        yield 'a word inside' => ['044 123 ab 67'];
        yield 'invalid UTF-8' => ["044 123 45 6\xff"];
        yield 'a NUL' => ["044 123 45 67\0"];
    }

    #[DataProvider('garbage')]
    public function test_garbage_fails(string $typed): void
    {
        $this->expectException(TransformationFailedException::class);

        new PhoneNumberTransformer()->reverseTransform($typed);
    }

    public function test_empty_is_null_both_ways(): void
    {
        $transformer = new PhoneNumberTransformer();

        self::assertNull($transformer->reverseTransform(''));
        self::assertNull($transformer->reverseTransform(null));
        self::assertSame('', $transformer->transform(null));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function stored(): iterable
    {
        yield 'landline' => ['+41441234567', '044 123 45 67'];
        yield 'free number' => ['+41800123456', '0800 123 456'];
        yield 'foreign' => ['+49301234567', '+49301234567'];
    }

    #[DataProvider('stored')]
    public function test_a_stored_number_shows_the_swiss_way_and_round_trips(string $e164, string $shown): void
    {
        $transformer = new PhoneNumberTransformer();

        self::assertSame($shown, $transformer->transform($e164));
        self::assertSame($e164, $transformer->reverseTransform($transformer->transform($e164)));
    }
}
