<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\EraseContact;
use App\Crm\Command\IngestInquiry;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryFactory;
use App\Inquiries\Entity\Inquiry;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:crm:backfill-inquiries` (C:2414, N9, N12): only what was never ingested, silent when there is nothing to do, one
 * failure costs one inquiry and exit 1, and an erased person does not come back.
 */
final class BackfillInquiriesCommandTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_ingests_only_the_inquiries_without_a_link(): void
    {
        $linked = $this->inquiry('linked@example.ch');
        $this->dispatch(new IngestInquiry($linked->id()));
        $missed = $this->inquiry('missed@example.ch');

        [$status, $output] = $this->backfill();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 inquiries ingested, 0 failed.', trim($output));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ?', $missed->id()));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_inquiry_links'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_contacts'));
        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_second_run_prints_nothing_and_writes_nothing(): void
    {
        $this->inquiry('missed@example.ch');
        $this->backfill();

        [$status, $output] = $this->backfill();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_failure_does_not_stop_the_rest_and_the_next_run_repairs_it(): void
    {
        $failing = $this->inquiry('failing@example.ch', '2026-10-01 10:00:00 UTC');
        $fine = $this->inquiry('fine@example.ch', '2026-10-02 10:00:00 UTC');
        $this->refuse($failing);

        [$status, $output] = $this->backfill();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            [$failing->id().': '.DriverException::class, '1 inquiries ingested, 1 failed.'],
            $this->lines($output),
        );
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ?', $failing->id()));
        self::assertSame(0, $this->rows("SELECT count(*) FROM crm_contacts WHERE email = 'failing@example.ch'"));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_inquiry_links WHERE inquiry_id = ?', $fine->id()));

        $this->connection->executeStatement('ALTER TABLE crm_inquiry_links DROP CONSTRAINT test_refused_inquiries');

        [$status, $output] = $this->backfill();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 inquiries ingested, 0 failed.', trim($output));
    }

    public function test_a_failure_inside_the_handler_is_named_by_its_own_class(): void
    {
        $failing = $this->inquiry('failing@example.ch');
        // The handler's lookup by e-mail fails, unlike a flush, which the bus runs after the handler and does not wrap.
        $this->connection->executeStatement('ALTER TABLE crm_contacts RENAME COLUMN email TO test_email');

        [$status, $output] = $this->backfill();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(
            [$failing->id().': '.InvalidFieldNameException::class, '0 inquiries ingested, 1 failed.'],
            $this->lines($output),
        );
    }

    public function test_the_oldest_comes_first(): void
    {
        $newer = $this->inquiry('newer@example.ch', '2026-10-02 10:00:00 UTC');
        $older = $this->inquiry('older@example.ch', '2026-10-01 10:00:00 UTC');
        $this->refuse($older, $newer);

        self::assertSame(
            [$older->id(), $newer->id()],
            array_map(static fn (string $line): string => explode(':', $line)[0], \array_slice($this->lines($this->backfill()[1]), 0, 2)),
        );
    }

    public function test_after_an_erasure_the_link_stays_empty_and_the_run_brings_nobody_back(): void
    {
        $inquiry = $this->inquiry('erased@example.ch');
        $this->dispatch(new IngestInquiry($inquiry->id()));
        $contactId = (string) $this->connection->fetchOne('SELECT contact_id FROM crm_inquiry_links');
        $this->em->clear();
        $this->dispatch(new EraseContact($contactId));

        [$status, $output] = $this->backfill();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
        self::assertSame(
            ['inquiry_id' => $inquiry->id(), 'contact_id' => null, 'activity_id' => null, 'status' => InquiryStatus::New->value],
            $this->connection->fetchAssociative('SELECT inquiry_id, contact_id, activity_id, status FROM crm_inquiry_links'),
        );
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_contacts'));
    }

    private function inquiry(string $email, string $createdAt = '2026-10-01 10:00:00 UTC'): Inquiry
    {
        return InquiryFactory::createOne(['email' => $email, 'createdAt' => new \DateTimeImmutable($createdAt)]);
    }

    /**
     * A real failed flush, which closes the EntityManager: the check goes with the test's transaction.
     */
    private function refuse(Inquiry ...$inquiries): void
    {
        $ids = implode(', ', array_map(static fn (Inquiry $inquiry): string => "'".$inquiry->id()."'", $inquiries));
        $this->connection->executeStatement(\sprintf('ALTER TABLE crm_inquiry_links ADD CONSTRAINT test_refused_inquiries CHECK (inquiry_id NOT IN (%s)) NOT VALID', $ids));
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map(trim(...), explode(\PHP_EOL, $output))));
    }

    /**
     * @return array{int, string}
     */
    private function backfill(): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:crm:backfill-inquiries'));
        $status = $tester->execute([]);

        return [$status, $tester->getDisplay()];
    }
}
