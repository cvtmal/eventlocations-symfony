<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Inquiries\ReadModel\InquiryVocabularyQuery;
use App\Inquiries\ValueObject\MailStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sibling of VocabularyChecksTest for `inquiries`: every enum in a CHECK-constrained column
 * has exactly the values of the CHECK's IN list, in the same order. The catering wish is a list of
 * term codes, not an enum.
 */
final class InquiryVocabularyChecksTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function checks(): iterable
    {
        yield 'venue mail status' => ['chk_inquiries_venue_mail_status', MailStatus::class];
        yield 'guest mail status' => ['chk_inquiries_guest_mail_status', MailStatus::class];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('checks')]
    public function test_check_lists_the_enum_values(string $constraint, string $enum): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $definition = $connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
        self::assertIsString($definition, $constraint);
        preg_match_all("/'([^']*)'::/", $definition, $matches);

        self::assertSame(array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()), $matches[1]);
    }

    public function test_catering_wish_check_lists_the_catering_wishes(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $definition = $connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', ['chk_inquiries_catering_wish']);
        self::assertIsString($definition);
        preg_match_all("/'([^']*)'::/", $definition, $matches);

        self::assertSame(InquiryVocabularyQuery::CATERING_WISHES, $matches[1]);
    }
}
