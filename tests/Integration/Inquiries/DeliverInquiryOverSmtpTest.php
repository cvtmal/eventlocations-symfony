<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\ValueObject\DeliveryOutcome;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\SmtpSink;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * The real bus, mailer and SMTP transport against a server without SMTPUTF8. Mailpit offers it,
 * so development cannot show this. The form refuses a non-ASCII local part, so the row is
 * written directly: an old row or a future form must not be able to undo a sent venue mail.
 */
final class DeliverInquiryOverSmtpTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const VENUE_MAIL_RECIPIENT = 'override@deine-eventlocation.test';
    private const REFUSED = 'abgelehnt@example.ch';

    private SmtpSink $sink;
    private mixed $serverDsn;
    private mixed $envDsn;
    private Connection $connection;
    private EntityManagerInterface $em;
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        $this->sink = SmtpSink::start(self::REFUSED);
        $this->serverDsn = $_SERVER['MAILER_DSN'] ?? null;
        $this->envDsn = $_ENV['MAILER_DSN'] ?? null;
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = $this->sink->dsn;

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->bus = $bus;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();

        $_SERVER['MAILER_DSN'] = $this->serverDsn;
        $_ENV['MAILER_DSN'] = $this->envDsn;
        $this->sink->stop();
    }

    public function test_an_address_the_server_cannot_carry_costs_only_the_guest_copy(): void
    {
        $id = InquiryFactory::createOne(['email' => 'müller@example.ch'])->id();

        $outcome = $this->deliver($id);

        self::assertTrue($outcome->venueMailSent);
        self::assertSame(['sent', 'failed', self::VENUE_MAIL_RECIPIENT], $this->statuses($id));
        self::assertSame([[self::VENUE_MAIL_RECIPIENT]], $this->sink->delivered());
    }

    public function test_a_retry_cannot_mail_the_venue_twice(): void
    {
        $id = InquiryFactory::createOne(['email' => 'müller@example.ch'])->id();

        $this->deliver($id);
        $outcome = $this->deliver($id);

        self::assertTrue($outcome->venueMailSent);
        self::assertSame(['sent', 'failed', self::VENUE_MAIL_RECIPIENT], $this->statuses($id));
        self::assertSame([[self::VENUE_MAIL_RECIPIENT]], $this->sink->delivered());
    }

    public function test_a_guest_address_the_server_refuses_costs_only_the_guest_copy(): void
    {
        $id = InquiryFactory::createOne(['email' => self::REFUSED])->id();

        $outcome = $this->deliver($id);

        self::assertTrue($outcome->venueMailSent);
        self::assertSame(['sent', 'failed', self::VENUE_MAIL_RECIPIENT], $this->statuses($id));
        self::assertSame([[self::VENUE_MAIL_RECIPIENT]], $this->sink->delivered());
    }

    public function test_an_idn_domain_needs_no_smtputf8(): void
    {
        $id = InquiryFactory::createOne(['email' => 'anna@müller.ch'])->id();

        $this->deliver($id);

        self::assertSame(['sent', 'sent', self::VENUE_MAIL_RECIPIENT], $this->statuses($id));
        self::assertSame([[self::VENUE_MAIL_RECIPIENT], ['anna@xn--mller-kva.ch']], $this->sink->delivered());
    }

    private function deliver(string $id): DeliveryOutcome
    {
        $outcome = $this->bus->dispatch(new DeliverInquiry($id))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(DeliveryOutcome::class, $outcome);
        $this->em->clear();

        return $outcome;
    }

    /**
     * @return array{mixed, mixed, mixed} venue mail, guest mail, envelope recipient
     */
    private function statuses(string $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT venue_mail_status, guest_mail_status, delivered_to FROM inquiries WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [$row['venue_mail_status'], $row['guest_mail_status'], $row['delivered_to']];
    }
}
