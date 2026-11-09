<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Mailer;

use App\Shared\Mailer\ReplyTo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReplyToTest extends TestCase
{
    public function test_a_valid_address_keeps_the_person_s_name(): void
    {
        $address = ReplyTo::person(' anna@example.ch ', ' Anna Meier ');

        self::assertNotNull($address);
        self::assertSame('anna@example.ch', $address->getAddress());
        self::assertSame('Anna Meier', $address->getName());
    }

    public function test_a_line_break_in_the_name_never_reaches_the_header(): void
    {
        $address = ReplyTo::person('anna@example.ch', "Anna\r\nBcc: x@example.ch");

        self::assertNotNull($address);
        self::assertStringNotContainsString("\n", $address->toString());
        self::assertStringNotContainsString("\r", $address->toString());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusable(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no address' => ['keine adresse'];
        yield 'header injection' => ["anna@example.ch\r\nBcc: x@example.ch"];
    }

    #[DataProvider('unusable')]
    public function test_an_unusable_address_gives_null(?string $email): void
    {
        self::assertNull(ReplyTo::person($email, 'Anna'));
    }
}
