<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Entity\Inquiry;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * The hourly staff alert (G7, D56): one mail to staff per run with every inquiry whose venue mail failed or is
 * stuck, each inquiry once. Cron mails whatever the command prints, so it prints only when that staff mail failed,
 * and then identifiers, never a guest's data.
 */
final class ReportStuckInquiriesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use ImportsTownDataset;

    private const string NOW = '2026-06-01 12:00:00 UTC';
    private const string FIRST_NAME = 'Annelies';
    private const string LAST_NAME = 'Bergmann';
    private const string EMAIL = 'annelies.bergmann@example.ch';
    private const string PHONE = '+41791234567';
    private const string MESSAGE = 'Wir suchen einen Saal mit Seesicht.';
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

    public function test_it_is_silent_and_mails_nothing_with_nothing_due(): void
    {
        InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable('2026-06-01 11:55:00 UTC')]);

        $tester = $this->runCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertEmailCount(0);
    }

    public function test_it_mails_staff_once_and_marks_the_rows(): void
    {
        $failed = $this->stuck('2026-06-01 11:59:00 UTC', failed: true);
        $pending = $this->stuck('2026-06-01 10:30:00 UTC');

        $tester = $this->runCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('hallo@deine-eventlocation.ch', $mail->getTo()[0]->getAddress());
        self::assertSame('Anfragen nicht zugestellt: 2', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();
        foreach ([$failed, $pending] as $inquiry) {
            self::assertMatchesRegularExpression('~href="https?://[^"/]+/admin/crm/anfragen/'.$inquiry->id().'"~', $html, 'an absolute CRM link');
            self::assertStringContainsString(\sprintf('%s (Nr. %d)', $inquiry->venueName(), $inquiry->venueNumber()), $text);
        }
        self::assertStringContainsString('Zustellung fehlgeschlagen', $text);
        self::assertStringContainsString('Zustellung ausstehend', $text);
        self::assertStringContainsString('eingegangen 01.06.2026 12:30', $text, 'Zurich time');
        foreach ([self::FIRST_NAME, self::LAST_NAME, self::EMAIL, self::PHONE, self::MESSAGE] as $personal) {
            self::assertStringNotContainsString($personal, $html);
            self::assertStringNotContainsString($personal, $text);
        }
        self::assertSame(2, $this->alerted());
    }

    public function test_a_second_run_sends_and_writes_nothing(): void
    {
        $this->stuck('2026-06-01 11:59:00 UTC', failed: true);
        $this->runCommand([]);
        $marked = $this->connection->fetchFirstColumn('SELECT stuck_alerted_at FROM inquiries ORDER BY id');

        $tester = $this->runCommand([]);

        self::assertSame('', $tester->getDisplay());
        self::assertEmailCount(1);
        self::assertSame($marked, $this->connection->fetchFirstColumn('SELECT stuck_alerted_at FROM inquiries ORDER BY id'));
    }

    public function test_the_minutes_option_moves_the_bound(): void
    {
        $this->stuck('2026-06-01 11:40:00 UTC');

        $this->runCommand(['--minutes' => 30]);
        self::assertEmailCount(0);

        $this->runCommand([]);
        self::assertEmailCount(1);
    }

    public function test_a_failed_staff_mail_prints_the_ids_and_retries_next_time(): void
    {
        $this->bootWithDeadSmtp();
        $older = $this->stuck('2026-06-01 09:30:00 UTC');
        $newer = $this->stuck('2026-06-01 11:59:00 UTC', failed: true);

        $tester = $this->runCommand([]);
        $display = $tester->getDisplay();
        $lines = explode("\n", trim($display));

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(3, $lines);
        self::assertSame(\sprintf('%s venue %d pending since 2026-06-01 09:30 UTC', $older->id(), $older->venueNumber()), $lines[0]);
        self::assertSame(\sprintf('%s venue %d failed since 2026-06-01 11:59 UTC', $newer->id(), $newer->venueNumber()), $lines[1]);
        self::assertSame('Mail to staff failed: resend them from the back office, or dispatch DeliverInquiry for each id.', $lines[2]);
        foreach ([self::FIRST_NAME, self::LAST_NAME, self::EMAIL, self::PHONE, self::MESSAGE] as $personal) {
            self::assertStringNotContainsString($personal, $display);
        }
        self::assertSame(0, $this->alerted(), 'nothing is marked, so the next run reports them again');
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

    private function stuck(string $createdAt, bool $failed = false): Inquiry
    {
        $inquiry = InquiryFactory::createOne([
            'firstName' => self::FIRST_NAME,
            'lastName' => self::LAST_NAME,
            'email' => self::EMAIL,
            'phone' => self::PHONE,
            'message' => self::MESSAGE,
            'createdAt' => new \DateTimeImmutable($createdAt),
        ]);
        if ($failed) {
            $inquiry->recordVenueMailFailed(new \DateTimeImmutable($createdAt));
            $this->em->flush();
        }

        return $inquiry;
    }

    private function alerted(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM inquiries WHERE stuck_alerted_at IS NOT NULL');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(array $input): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:inquiries:report-stuck'));
        $tester->execute($input);

        return $tester;
    }
}
