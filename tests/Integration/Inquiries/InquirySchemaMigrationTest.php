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
        "INSERT INTO inquiries (id, venue_id, venue_number, venue_name, venue_slug, recipient_email, locale, occasion, guest_count, message, first_name, last_name, email, fingerprint) VALUES ('{inquiry}', '{venue}', 1, 'Testhaus', 'testhaus', 'info@testhaus.example', 'de', 'wedding', 100, 'Hallo', 'Anna', 'Muster', 'anna@example.ch', repeat('a', 64))",
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
        yield 'unknown occasion' => ['chk_inquiries_occasion', "UPDATE inquiries SET occasion = 'birthday'"];
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
        yield 'open date, no phone' => ['UPDATE inquiries SET event_date = NULL, phone = NULL'];
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
        $row = $this->connection->fetchAssociative('SELECT venue_mail_status, guest_mail_status, delivered_to, venue_mail_sent_at FROM inquiries');

        self::assertSame(['venue_mail_status' => 'pending', 'guest_mail_status' => 'pending', 'delivered_to' => null, 'venue_mail_sent_at' => null], $row);
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
