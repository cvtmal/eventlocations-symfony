<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\SendCrmEmail;
use App\Crm\CommandHandler\SendCrmEmailHandler;
use App\Crm\Exception\ActivityReadOnly;
use App\Crm\Mailer\CrmMailer;
use App\Crm\OwnActivity;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\ContactRepository;
use App\Crm\ValueObject\RecordKind;
use App\Factory\AdminUserFactory;
use App\Factory\ContactFactory;
use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Tests\Support\Inquiries\RecordingMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Zenstruck\Foundry\Test\Factories;

/**
 * The handler built by hand on `RecordingMailer`, which fails where the real pipeline can, so each outcome is forced.
 * `EmailApiTest` has the same send through the real mailer and transport. The flush stands in for the bus's transaction.
 */
final class SendCrmEmailTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string ADDRESS = 'mia@example.ch';
    private const string CC = 'tom@example.ch';
    private const string OVERRIDE = 'override@example.test';
    private const string SUBJECT = 'Ihre Offerte';
    private const string BODY = 'Guten Tag';

    public function test_a_sent_mail_stores_the_entry_with_the_real_recipient_under_the_override(): void
    {
        $recording = new RecordingMailer();
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        ($this->handler($recording, self::OVERRIDE))($this->command($id, $contact->id(), cc: self::CC));
        $this->em->flush();

        self::assertCount(1, $recording->messages);
        self::assertSame([self::OVERRIDE], array_map(static fn (Address $address): string => $address->getAddress(), $recording->messages[0]->getTo()));
        self::assertEquals(['to' => self::ADDRESS, 'cc' => self::CC, 'status' => 'sent'], $this->payload($id));
        self::assertSame(
            ['type_code' => 'email_sent', 'title' => self::SUBJECT, 'body' => self::BODY, 'at' => '2026-11-01 10:00:00'],
            $this->connection->fetchAssociative("SELECT type_code, title, body, to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS at FROM crm_activities WHERE id = ?", [$id]),
        );
    }

    public function test_a_mail_without_a_cc_stores_no_cc_key(): void
    {
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        ($this->handler(new RecordingMailer()))($this->command($id, $contact->id()));
        $this->em->flush();

        self::assertEquals(['to' => self::ADDRESS, 'status' => 'sent'], $this->payload($id));
    }

    /**
     * @return iterable<string, array{\Throwable, class-string<\Throwable>}>
     */
    public static function mailFailures(): iterable
    {
        yield 'a transport error' => [new TransportException('SMTP refused mia@example.ch'), TransportException::class];
        yield 'an error in the pipeline' => [new \RuntimeException('Not for mia@example.ch'), \RuntimeException::class];
    }

    public function test_a_sent_mail_logs_its_entry_and_nothing_of_the_mail(): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        ($this->handler(new RecordingMailer(), logger: new Logger(LogLevel::INFO, $output)))($this->command($id, $contact->id()));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[info] Crm mail '.$id.' accepted by SMTP.', $line);
        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringNotContainsString(self::ADDRESS, $line);
        self::assertStringNotContainsString(self::SUBJECT, $line);
        self::assertStringNotContainsString(self::BODY, $line);
    }

    /**
     * @param class-string<\Throwable> $cause
     */
    #[DataProvider('mailFailures')]
    public function test_a_mail_failure_stores_a_failed_entry_and_logs_where_not_what(\Throwable $failure, string $cause): void
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        ($this->handler(new RecordingMailer($failure), logger: new Logger(LogLevel::ERROR, $output)))($this->command($id, $contact->id()));
        $this->em->flush();

        self::assertEquals(['to' => self::ADDRESS, 'status' => 'failed'], $this->payload($id));
        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Crm mail '.$id.' failed ('.$cause.' at ', $line);
        self::assertStringNotContainsString('mia', $line);
    }

    /**
     * `Address` refuses it while the message is built, so nothing reaches the mailer.
     */
    public function test_an_address_the_mailer_refuses_stores_a_failed_entry(): void
    {
        $recording = new RecordingMailer();
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        ($this->handler($recording))($this->command($id, $contact->id(), cc: 'kaputt'));
        $this->em->flush();

        self::assertSame([], $recording->messages);
        self::assertEquals(['to' => self::ADDRESS, 'cc' => 'kaputt', 'status' => 'failed'], $this->payload($id));
    }

    public function test_an_unexpected_error_bubbles_and_stores_nothing(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \LogicException('Kaputt.');
            }
        };
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);

        try {
            ($this->handler($mailer))($this->command(EntityId::generate(), $contact->id()));
            self::fail('The error should have bubbled.');
        } catch (\LogicException $error) {
            self::assertSame('Kaputt.', $error->getMessage());
        }

        $this->em->flush();
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_missing_sender_stops_before_any_mail_leaves(): void
    {
        $recording = new RecordingMailer();
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);

        try {
            ($this->handler($recording))($this->command(EntityId::generate(), $contact->id(), actingUserId: EntityId::generate()));
            self::fail('A missing sender should stop the send.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('staff member', $error->getMessage());
        }

        self::assertSame([], $recording->messages);
    }

    public function test_a_missing_type_stops_before_any_mail_leaves(): void
    {
        $recording = new RecordingMailer();
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $this->connection->delete('crm_activity_types', ['code' => 'email_sent']);

        try {
            ($this->handler($recording))($this->command(EntityId::generate(), $contact->id()));
            self::fail('A missing type should stop the send.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('migration', $error->getMessage());
        }

        self::assertSame([], $recording->messages);
    }

    public function test_the_entry_is_read_only_for_its_author(): void
    {
        $author = AdminUserFactory::createOne();
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();
        ($this->handler(new RecordingMailer()))($this->command($id, $contact->id(), actingUserId: $author->id()));
        $this->em->flush();

        $own = self::getContainer()->get(OwnActivity::class);
        self::assertInstanceOf(OwnActivity::class, $own);

        $this->expectException(ActivityReadOnly::class);
        $own->lock($id, $author->id());
    }

    public function test_the_audit_row_holds_the_type_only(): void
    {
        $contact = ContactFactory::createOne(['email' => self::ADDRESS]);
        $id = EntityId::generate();

        $this->dispatch($this->command($id, $contact->id(), cc: self::CC));

        self::assertSame(
            [['command' => SendCrmEmail::class, 'changes' => ['type' => 'email_sent']]],
            $this->auditRows('activity', $id),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $id): array
    {
        $payload = json_decode((string) $this->connection->fetchOne('SELECT payload::text FROM crm_activities WHERE id = ?', [$id]), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /* @var array<string, mixed> $payload */
        return $payload;
    }

    private function command(string $id, string $contactId, ?string $cc = null, ?string $actingUserId = null): SendCrmEmail
    {
        $companyId = (string) $this->connection->fetchOne('SELECT company_id FROM crm_contacts WHERE id = ?', [$contactId]);

        return new SendCrmEmail($id, RecordKind::Company, $companyId, $contactId, $cc, self::SUBJECT, self::BODY, $actingUserId ?? AdminUserFactory::createOne()->id());
    }

    private function handler(MailerInterface $mailer, ?string $override = null, LoggerInterface $logger = new NullLogger()): SendCrmEmailHandler
    {
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $contacts = self::getContainer()->get(ContactRepository::class);
        self::assertInstanceOf(ContactRepository::class, $contacts);

        return new SendCrmEmailHandler(
            $companies,
            $contacts,
            new CrmMailer($mailer, new MarkdownRenderer(), new EnabledLocale(['de'], 'de'), 'hallo@deine-eventlocation.ch', 'deine-eventlocation.ch <hallo@deine-eventlocation.ch>', $override),
            $this->em,
            new MockClock('2026-11-01 10:00:00 UTC'),
            $logger,
        );
    }
}
