<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\InquiryStatus;
use App\Crm\ValueObject\LostReason;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sibling of VocabularyChecksTest for the CRM tables: every enum in a CHECK-constrained column
 * has exactly the values of the CHECK's IN list, in the same order.
 */
final class CrmVocabularyChecksTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function checks(): iterable
    {
        yield 'company stage' => ['chk_crm_companies_stage', CompanyStage::class];
        yield 'company origin' => ['chk_crm_companies_origin', CompanyOrigin::class];
        yield 'lost reason' => ['chk_crm_deals_lost_reason', LostReason::class];
        yield 'inquiry status' => ['chk_crm_inquiry_links_status', InquiryStatus::class];
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
}
