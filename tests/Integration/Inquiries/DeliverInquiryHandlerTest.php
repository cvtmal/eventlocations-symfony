<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Command\DeliverInquiry;
use App\Inquiries\CommandHandler\DeliverInquiryHandler;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\Mailer\InquiryMailer;
use App\Inquiries\Repository\InquiryRepository;
use App\Inquiries\ValueObject\MailStatus;
use App\Shared\Twig\SwissFormatExtension;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\RecordingMailer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Built by hand: `MailerInterface` is inlined in the test container, so a failing mailer
 * cannot be swapped in. The real pipeline: `SubmitInquiryMailErrorTest`,
 * `SubmitInquiryDeliveryFailureTest` and `DeliverInquiryOverSmtpTest`.
 */
final class DeliverInquiryHandlerTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const NOW = '2026-06-01 12:00:00 UTC';

    private Connection $connection;
    private EntityManagerInterface $em;
    private InquiryRepository $inquiries;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $inquiries = self::getContainer()->get(InquiryRepository::class);
        self::assertInstanceOf(InquiryRepository::class, $inquiries);
        $this->inquiries = $inquiries;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function test_both_mails_sent(): void
    {
        $mailer = new RecordingMailer();
        $id = InquiryFactory::createOne()->id();

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        self::assertCount(2, $mailer->messages);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Sent, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Sent, $inquiry->guestMailStatus());
        self::assertSame('override@example.test', $inquiry->deliveredTo());
        self::assertNotNull($inquiry->venueMailSentAt());
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp(), $inquiry->venueMailSentAt()->getTimestamp());
    }

    public function test_a_failed_venue_mail_skips_the_guest_copy(): void
    {
        $mailer = new RecordingMailer(new TransportException('SMTP down'));
        $id = InquiryFactory::createOne()->id();

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertFalse($outcome->venueMailSent);
        self::assertSame([], $mailer->messages);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Failed, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Skipped, $inquiry->guestMailStatus());
        self::assertNull($inquiry->deliveredTo());
        self::assertNull($inquiry->venueMailSentAt());
    }

    public function test_a_failed_guest_copy_still_counts_as_delivered(): void
    {
        $mailer = new RecordingMailer(new TransportException('SMTP down'), failOnSend: 2);
        $id = InquiryFactory::createOne()->id();

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Sent, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Failed, $inquiry->guestMailStatus());
    }

    public function test_a_non_transport_error_of_the_venue_mail_is_a_failure_not_an_error(): void
    {
        $mailer = new RecordingMailer(new InvalidArgumentException('Not for this server.'));
        $id = InquiryFactory::createOne()->id();

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertFalse($outcome->venueMailSent);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Failed, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Skipped, $inquiry->guestMailStatus());
    }

    public function test_a_non_transport_error_of_the_guest_copy_keeps_the_sent_venue_mail(): void
    {
        $mailer = new RecordingMailer(new InvalidArgumentException('Not for this server.'), failOnSend: 2);
        $id = InquiryFactory::createOne()->id();

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Sent, $inquiry->venueMailStatus());
        self::assertSame('override@example.test', $inquiry->deliveredTo());
        self::assertSame(MailStatus::Failed, $inquiry->guestMailStatus());
    }

    /**
     * The line an operator reads: the app has no Monolog, so `logger` is this class, and it prints
     * the message with its placeholders filled and nothing else of the context.
     */
    public function test_the_log_line_names_the_mail_the_id_and_the_cause_and_no_personal_data(): void
    {
        self::assertInstanceOf(Logger::class, self::getContainer()->get('logger'));
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $mailer = new RecordingMailer(new InvalidArgumentException('Not for anna@example.ch.'), failOnSend: 2);
        $inquiry = InquiryFactory::createOne(['email' => 'anna@example.ch', 'firstName' => 'Anna']);
        $id = $inquiry->id();

        $this->handler($mailer, logger: new Logger(LogLevel::ERROR, $output))(new DeliverInquiry($id));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Inquiry '.$id.': the guest mail failed ('.InvalidArgumentException::class.' at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', strtolower($line));
    }

    public function test_the_log_line_of_a_failed_venue_mail_says_so(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $id = InquiryFactory::createOne()->id();

        $this->handler(new RecordingMailer(new TransportException('SMTP down')), logger: new Logger(LogLevel::ERROR, $output))(new DeliverInquiry($id));

        self::assertStringContainsString('[error] Inquiry '.$id.': the venue mail failed ('.TransportException::class.' at ', (string) stream_get_contents($output, offset: 0));
    }

    public function test_a_second_run_never_mails_the_venue_again(): void
    {
        $mailer = new RecordingMailer(new TransportException('SMTP down'), failOnSend: 2);
        $id = InquiryFactory::createOne()->id();
        $handler = $this->handler($mailer);

        $handler(new DeliverInquiry($id));
        $this->reloaded($id);
        $outcome = $handler(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        self::assertCount(1, $mailer->messages);
        $inquiry = $this->reloaded($id);
        self::assertSame(MailStatus::Sent, $inquiry->venueMailStatus());
        self::assertSame(MailStatus::Failed, $inquiry->guestMailStatus());
    }

    /**
     * The entity in memory is from before the wait for the claim, as it is for a second POST.
     */
    public function test_the_guard_reads_the_row_and_not_the_entity_in_memory(): void
    {
        $mailer = new RecordingMailer();
        $id = InquiryFactory::createOne()->id();
        self::assertSame(MailStatus::Pending, $this->inquiries->get($id)->venueMailStatus());
        $this->connection->executeStatement("UPDATE inquiries SET venue_mail_status = 'sent', guest_mail_status = 'sent' WHERE id = ?", [$id]);

        $outcome = $this->handler($mailer)(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        self::assertSame([], $mailer->messages);
    }

    public function test_a_failed_venue_mail_may_run_again(): void
    {
        $mailer = new RecordingMailer(new TransportException('SMTP down'));
        $id = InquiryFactory::createOne()->id();
        $handler = $this->handler($mailer);

        $handler(new DeliverInquiry($id));
        $this->reloaded($id);
        $outcome = $handler(new DeliverInquiry($id));

        self::assertTrue($outcome->venueMailSent);
        self::assertCount(2, $mailer->messages);
        self::assertSame(MailStatus::Sent, $this->reloaded($id)->guestMailStatus());
    }

    public function test_a_real_bad_venue_address_is_a_failure_not_an_error(): void
    {
        $inquiry = InquiryFactory::createOne();
        $this->connection->executeStatement('UPDATE inquiries SET recipient_email = ? WHERE id = ?', ['a..b@x.ch', $inquiry->id()]);
        $id = $inquiry->id();
        $this->em->clear();

        // Without an override the mailer builds an Address from the stored value.
        $outcome = $this->handler(new RecordingMailer(), override: null)(new DeliverInquiry($id));

        self::assertFalse($outcome->venueMailSent);
        self::assertSame(MailStatus::Failed, $this->reloaded($id)->venueMailStatus());
    }

    private function handler(RecordingMailer $mailer, ?string $override = 'override@example.test', LoggerInterface $logger = new NullLogger()): DeliverInquiryHandler
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $format = self::getContainer()->get(SwissFormatExtension::class);
        self::assertInstanceOf(SwissFormatExtension::class, $format);

        return new DeliverInquiryHandler(
            $this->inquiries,
            new InquiryMailer($mailer, $translator, $format, 'hallo@deine-eventlocation.ch', $override),
            new MockClock(self::NOW),
            $logger,
        );
    }

    private function reloaded(string $id): Inquiry
    {
        $this->em->flush();
        $this->em->clear();

        return $this->inquiries->get($id);
    }
}
