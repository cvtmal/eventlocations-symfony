<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The constraints of `inquiries`, proven against the real schema with raw SQL inside a
 * transaction that is rolled back. test_every_constraint_has_a_rejection_case() lists every
 * chk_/uk_/fk_ name of the table, so a new constraint without a case here fails.
 */
final class InquirySchemaMigrationTest extends KernelTestCase
{
    private const IDS = [
        '{town}' => '019a0000-0000-7000-8000-000000000001',
        '{venue}' => '019a0000-0000-7000-8000-000000000101',
        '{inquiry}' => '019a0000-0000-7000-8000-000000000501',
        '{missing}' => '019a0000-0000-7000-8000-000000000999',
    ];

    private const SEED = [
        "INSERT INTO towns (id, canton_code, zip, name, slug, bfs_number, lat, lng, population) VALUES ('{town}', 'zh', '8999', 'Testdorf Inquiries', 'testdorf-inquiries', 9999, 47.3, 8.5, 100)",
        "INSERT INTO venues (id, name, status, capacity_max, zip, town_id, canton_code, lat, lng, inquiry_email) VALUES ('{venue}', 'Testhaus', 'published', 100, '8999', '{town}', 'zh', 47.3, 8.5, 'info@testhaus.example')",
        "INSERT INTO inquiries (id, venue_id, venue_number, venue_name, venue_slug, recipient_email, locale, event_type, date_flexible, guest_count, message, first_name, last_name, email, fingerprint) VALUES ('{inquiry}', '{venue}', 1, 'Testhaus', 'testhaus', 'info@testhaus.example', 'de', 'corporate_event', true, 100, 'Hallo', 'Anna', 'Muster', 'anna@example.ch', repeat('a', 64))",
    ];

    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->beginTransaction();

        foreach (self::SEED as $sql) {
            $this->sql($sql);
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejections(): iterable
    {
        yield 'upper-case locale' => ['chk_inquiries_locale', "UPDATE inquiries SET locale = 'DE'"];
        yield 'event type not a term code' => ['chk_inquiries_event_type', "UPDATE inquiries SET event_type = 'Firmenanlass'"];
        yield 'blank company' => ['chk_inquiries_company', "UPDATE inquiries SET company = '  '"];
        yield 'neither date nor flexible' => ['chk_inquiries_event_date_or_flexible', 'UPDATE inquiries SET event_date = NULL, date_flexible = false'];
        yield 'unknown catering wish' => ['chk_inquiries_catering_wish', "UPDATE inquiries SET catering_wish = 'buffet'"];
        yield 'zero budget' => ['chk_inquiries_budget_chf', 'UPDATE inquiries SET budget_chf = 0'];
        yield 'budget over a million' => ['chk_inquiries_budget_chf', 'UPDATE inquiries SET budget_chf = 1000001'];
        yield 'no guests' => ['chk_inquiries_guest_count', 'UPDATE inquiries SET guest_count = 0'];
        yield '5001 guests' => ['chk_inquiries_guest_count', 'UPDATE inquiries SET guest_count = 5001'];
        yield 'blank message' => ['chk_inquiries_message', "UPDATE inquiries SET message = '   '"];
        yield 'message of 5001 characters' => ['chk_inquiries_message', "UPDATE inquiries SET message = repeat('a', 5001)"];
        yield 'email without at' => ['chk_inquiries_email', "UPDATE inquiries SET email = 'anna.example.ch'"];
        yield 'email with a space' => ['chk_inquiries_email', "UPDATE inquiries SET email = 'a b@example.ch'"];
        yield 'fingerprint too short' => ['chk_inquiries_fingerprint', "UPDATE inquiries SET fingerprint = 'abc'"];
        yield 'fingerprint not hex' => ['chk_inquiries_fingerprint', "UPDATE inquiries SET fingerprint = repeat('g', 64)"];
        yield 'unknown venue mail status' => ['chk_inquiries_venue_mail_status', "UPDATE inquiries SET venue_mail_status = 'queued'"];
        yield 'unknown guest mail status' => ['chk_inquiries_guest_mail_status', "UPDATE inquiries SET guest_mail_status = 'queued'"];
        yield 'unknown qualification' => ['chk_inquiries_qualification', "UPDATE inquiries SET qualification = 'maybe'"];
        yield 'unknown qualification reason' => ['chk_inquiries_qualification_reason', "UPDATE inquiries SET qualification = 'unqualified', qualification_reason = 'too_cheap'"];
        yield 'unknown override' => ['chk_inquiries_qualification_override', "UPDATE inquiries SET qualification_override = 'fraud'"];
        yield 'qualified with a reason' => ['chk_inquiries_qualification_reason_pair', "UPDATE inquiries SET qualification = 'qualified', qualification_reason = 'duplicate'"];
        yield 'unqualified without a reason' => ['chk_inquiries_qualification_reason_pair', "UPDATE inquiries SET qualification = 'unqualified', qualification_reason = NULL"];
        yield 'a reason without a qualification' => ['chk_inquiries_qualification_reason_pair', "UPDATE inquiries SET qualification = NULL, qualification_reason = 'duplicate'"];
        yield 'unknown venue' => ['fk_inquiries_venue_id', "UPDATE inquiries SET venue_id = '{missing}'"];
    }

    #[DataProvider('rejections')]
    public function test_constraint_rejects(string $constraint, string $statement): void
    {
        try {
            $this->sql($statement);
        } catch (DriverException $exception) {
            self::assertStringContainsString($constraint, $exception->getMessage());

            return;
        }

        self::fail($constraint.' accepted the statement.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptances(): iterable
    {
        yield 'smallest guest count' => ['UPDATE inquiries SET guest_count = 1'];
        yield 'largest guest count' => ['UPDATE inquiries SET guest_count = 5000'];
        yield 'message of 5000 characters' => ["UPDATE inquiries SET message = repeat('ä', 5000)"];
        yield 'umlaut domain' => ["UPDATE inquiries SET email = 'info@schüür.be'"];
        yield 'every status' => ["UPDATE inquiries SET venue_mail_status = 'failed', guest_mail_status = 'skipped'"];
        yield 'flexible date, no phone' => ['UPDATE inquiries SET event_date = NULL, date_flexible = true, phone = NULL'];
        yield 'a date, not flexible' => ["UPDATE inquiries SET event_date = '2030-06-15', date_flexible = false"];
        yield 'every event field' => ["UPDATE inquiries SET company = 'Muster AG', start_time = '18:30', catering_wish = 'external_allowed', budget_chf = 1000000"];
        yield 'smallest budget, in-house catering' => ["UPDATE inquiries SET budget_chf = 1, catering_wish = 'in_house'"];
        yield 'unqualified duplicate, marked test' => ["UPDATE inquiries SET qualification = 'unqualified', qualification_reason = 'duplicate', qualification_override = 'test'"];
        yield 'qualified, marked spam' => ["UPDATE inquiries SET qualification = 'qualified', qualification_reason = NULL, qualification_override = 'spam'"];
        yield 'stuck alert sent' => ['UPDATE inquiries SET stuck_alerted_at = now()'];
    }

    #[DataProvider('acceptances')]
    public function test_constraints_accept(string $statement): void
    {
        self::assertSame(1, $this->sql($statement));
    }

    public function test_every_constraint_has_a_rejection_case(): void
    {
        $names = $this->connection->fetchFirstColumn(
            "SELECT conname FROM pg_constraint WHERE conrelid = 'inquiries'::regclass AND contype IN ('c', 'u', 'f')",
        );
        $tested = array_values(array_unique(array_map(static fn (array $case): string => $case[0], iterator_to_array(self::rejections()))));

        sort($names);
        sort($tested);
        self::assertSame($names, $tested);
    }

    public function test_new_rows_start_pending(): void
    {
        $row = $this->connection->fetchAssociative('SELECT venue_mail_status, guest_mail_status, delivered_to, venue_mail_sent_at, qualification, qualification_reason, qualification_override, stuck_alerted_at FROM inquiries');

        self::assertSame([
            'venue_mail_status' => 'pending',
            'guest_mail_status' => 'pending',
            'delivered_to' => null,
            'venue_mail_sent_at' => null,
            'qualification' => null,
            'qualification_reason' => null,
            'qualification_override' => null,
            'stuck_alerted_at' => null,
        ], $row);
    }

    public function test_deleting_the_venue_keeps_the_inquiry_and_its_snapshot(): void
    {
        $this->sql("DELETE FROM venues WHERE id = '{venue}'");

        $row = $this->connection->fetchAssociative('SELECT venue_id, venue_name, recipient_email FROM inquiries');

        self::assertSame(['venue_id' => null, 'venue_name' => 'Testhaus', 'recipient_email' => 'info@testhaus.example'], $row);
    }

    private function sql(string $sql): int
    {
        return (int) $this->connection->executeStatement(strtr($sql, self::IDS));
    }
}
