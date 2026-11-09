<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFeedbackFactory;
use App\Inquiries\FeedbackToken;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\StoresDeliveredInquiries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The nightly feedback job (G16): one mail per address and run, with the opt-out link in the footer, the text part and
 * `List-Unsubscribe`; rows only after the mail went out, so a second run and a failed mail write nothing; an address
 * that opted out gets no mail, and the job carries its opt-out on to the new inquiry.
 */
final class RequestFeedbackCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;
    use StoresDeliveredInquiries;

    private const string NOW = '2026-06-15 03:15:00 UTC';
    private const string DEAD_DSN = 'smtp://127.0.0.1:1';

    private Connection $connection;
    private EntityManagerInterface $em;
    private mixed $serverDsn = null;
    private mixed $envDsn = null;
    private bool $dsnReplaced = false;

    protected function setUp(): void
    {
        $this->begin();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();

        if ($this->dsnReplaced) {
            $_SERVER['MAILER_DSN'] = $this->serverDsn;
            $_ENV['MAILER_DSN'] = $this->envDsn;
        }
    }

    public function test_one_mail_per_address_asks_about_each_inquiry_and_carries_the_opt_out(): void
    {
        $first = $this->delivered('2026-06-04 10:00:00 UTC', ['email' => 'Anna@example.ch', 'firstName' => 'Anna']);
        $second = $this->delivered('2026-06-06 10:00:00 UTC', ['email' => 'anna@example.ch', 'firstName' => 'Anna']);
        $this->delivered('2026-06-06 10:00:00 UTC', ['email' => 'spam@example.ch'], mark: InquiryOverride::Spam);

        $tester = $this->runCommand(OutputInterface::VERBOSITY_NORMAL);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('1 feedback mails sent, 0 addresses opted out, 0 mails failed.', trim($tester->getDisplay()));
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        $mail = $mails[0];
        self::assertSame('anna@example.ch', $mail->getTo()[0]->getAddress());
        self::assertSame('Hat es mit deinen Anfragen geklappt?', $mail->getSubject());

        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Hallo Anna', $html);
        foreach ([$first, $second] as $inquiry) {
            self::assertStringContainsString('Rückmeldung zu '.$inquiry->venueName().': '.$this->link($inquiry->id()), $text);
        }
        $optOut = $this->link($first->id()).'/abmelden';
        self::assertStringContainsString('Hier abmelden: '.$optOut, $text);
        self::assertSame('<'.$optOut.'>', $mail->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $mail->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());

        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM inquiry_feedback WHERE mailed_at = '2026-06-15 03:15:00+00' AND opted_out_at IS NULL"));
    }

    public function test_a_single_inquiry_names_its_venue_and_a_second_run_sends_and_writes_nothing(): void
    {
        $inquiry = $this->delivered('2026-06-05 10:00:00 UTC');

        $this->runCommand();
        $mails = $this->sentMails();
        self::assertCount(1, $mails);
        self::assertSame(\sprintf('Hat es mit %s geklappt?', $inquiry->venueName()), $mails[0]->getSubject());
        self::assertStringContainsString('Rückmeldung geben: '.$this->link($inquiry->id())."\n", (string) $mails[0]->getTextBody());

        $updatedAt = $this->connection->fetchOne('SELECT updated_at FROM inquiry_feedback');
        $tester = $this->runCommand();

        self::assertSame('', $tester->getDisplay());
        self::assertCount(1, $this->sentMails(), 'no second mail');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_feedback'));
        self::assertSame($updatedAt, $this->connection->fetchOne('SELECT updated_at FROM inquiry_feedback'));
    }

    public function test_an_opted_out_address_gets_no_mail_and_the_opt_out_moves_to_the_new_inquiry(): void
    {
        $earlier = $this->delivered('2026-03-01 10:00:00 UTC', ['email' => 'anna@example.ch']);
        InquiryFeedbackFactory::new()->optedOut()->create(['inquiry' => $earlier, 'at' => new \DateTimeImmutable('2026-03-08 03:15:00 UTC')]);
        $later = $this->delivered('2026-06-05 10:00:00 UTC', ['email' => 'anna@example.ch']);

        $tester = $this->runCommand(OutputInterface::VERBOSITY_NORMAL);

        self::assertSame('0 feedback mails sent, 1 addresses opted out, 0 mails failed.', trim($tester->getDisplay()));
        self::assertSame([], $this->sentMails());
        self::assertTrue((bool) $this->connection->fetchOne(
            "SELECT mailed_at IS NULL AND opted_out_at = '2026-06-15 03:15:00+00' FROM inquiry_feedback WHERE inquiry_id = ?",
            [$later->id()],
        ));
    }

    public function test_a_failed_mail_writes_nothing_and_prints_one_line_under_quiet(): void
    {
        $this->bootWithDeadSmtp();
        $this->delivered('2026-06-05 10:00:00 UTC');

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('1 feedback mails failed; they are retried next night.', trim($tester->getDisplay()));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM inquiry_feedback'));
    }

    private function begin(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        self::mockTime(self::NOW);
    }

    /**
     * Nothing listens on port 1, so the transport fails. The DSN is read when the container builds the transport,
     * so the kernel boots again after it is set; tearDown puts it back.
     */
    private function bootWithDeadSmtp(): void
    {
        $this->connection->rollBack();
        self::ensureKernelShutdown();
        $this->serverDsn = $_SERVER['MAILER_DSN'] ?? null;
        $this->envDsn = $_ENV['MAILER_DSN'] ?? null;
        $this->dsnReplaced = true;
        $_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = self::DEAD_DSN;
        $this->begin();
    }

    /**
     * @return list<Email>
     */
    private function sentMails(): array
    {
        $mails = [];
        foreach (self::getMailerEvents() as $event) {
            $message = $event->getMessage();
            if (!$event->isQueued() && $message instanceof Email) {
                $mails[] = $message;
            }
        }

        return $mails;
    }

    private function link(string $inquiryId): string
    {
        $tokens = self::getContainer()->get(FeedbackToken::class);
        self::assertInstanceOf(FeedbackToken::class, $tokens);
        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);

        return $urls->generate('inquiry_feedback', ['token' => $tokens->forInquiry($inquiryId)], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function runCommand(int $verbosity = OutputInterface::VERBOSITY_QUIET): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:inquiries:request-feedback'));
        $tester->execute([], ['verbosity' => $verbosity]);

        return $tester;
    }
}
