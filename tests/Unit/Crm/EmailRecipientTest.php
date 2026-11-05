<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ReadModel\CompanyRecord;
use App\Crm\ReadModel\CompanyRecordContact;
use App\Crm\ReadModel\EmailRecipient;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use PHPUnit\Framework\TestCase;

final class EmailRecipientTest extends TestCase
{
    public function test_a_company_gives_its_own_address_first_then_its_contacts_with_one(): void
    {
        $recipients = EmailRecipient::ofCompany($this->company('info@seeblick.example', [
            new CompanyRecordContact('c-1', null, 'Aebi', null, 'aebi@seeblick.example'),
            new CompanyRecordContact('c-2', 'Rolf', 'Keller', null),
            new CompanyRecordContact('c-3', 'Laura', 'Meier', null, 'laura@seeblick.example'),
        ]));

        self::assertEquals([
            new EmailRecipient(null, 'Seeblick Events GmbH', 'info@seeblick.example'),
            new EmailRecipient('c-1', 'Aebi', 'aebi@seeblick.example'),
            new EmailRecipient('c-3', 'Laura Meier', 'laura@seeblick.example'),
        ], $recipients);
    }

    public function test_a_company_without_an_address_gives_its_contacts_only(): void
    {
        $recipients = EmailRecipient::ofCompany($this->company(null, [
            new CompanyRecordContact('c-1', 'Laura', 'Meier', null, 'laura@seeblick.example'),
        ]));

        self::assertEquals([new EmailRecipient('c-1', 'Laura Meier', 'laura@seeblick.example')], $recipients);
    }

    public function test_a_company_with_no_address_anywhere_gives_nobody(): void
    {
        self::assertSame([], EmailRecipient::ofCompany($this->company(null, [new CompanyRecordContact('c-1', 'Rolf', 'Keller', null)])));
    }

    /**
     * @param list<CompanyRecordContact> $contacts
     */
    private function company(?string $email, array $contacts): CompanyRecord
    {
        return new CompanyRecord(
            'company-1',
            'Seeblick Events GmbH',
            null,
            null,
            null,
            $email,
            null,
            CompanyStage::cases()[0],
            null,
            null,
            null,
            CompanyOrigin::cases()[0],
            new \DateTimeImmutable('2026-11-01'),
            null,
            $contacts,
            false,
        );
    }
}
