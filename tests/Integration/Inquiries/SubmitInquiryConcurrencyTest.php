<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Inquiries\Command\SubmitInquiry;
use App\Inquiries\ValueObject\InquiryFingerprint;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Inquiries\SmtpSink;
use App\Tests\Support\Venues\DetailVenues;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Process\Process;
use Zenstruck\Foundry\Test\Factories;

/**
 * A double submit as it really happens: two processes, two connections, real commits, a real SMTP
 * server. There is no timing in it. The test holds the lock the POSTs need, waits until Postgres
 * reports both of them blocked on it, and lets go, so they overlap on every run, however loaded
 * the host is. Nothing here can roll back, so the fixtures are deleted by hand.
 */
final class SubmitInquiryConcurrencyTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private const SLUG = 'haus-nebenlaeufig';
    private const NAME = 'Haus Nebenläufig';
    private const VENUE_MAIL_RECIPIENT = 'override@hochzeitslocation.test';
    private const GUEST = 'anna@example.ch';
    private const DEAD_DSN = 'smtp://127.0.0.1:1';
    private const WAIT_SECONDS = 60;

    private const WAITING_FOR_THE_FINGERPRINT = <<<'SQL'
        SELECT count(*) FROM pg_locks l JOIN pg_database d ON d.oid = l.database
        WHERE l.locktype = 'advisory' AND NOT l.granted AND d.datname = current_database()
        SQL;

    // Not pg_stat_activity: inside a transaction, which holds the row here, it is a frozen snapshot.
    private const WAITING_FOR_THE_ROW = <<<'SQL'
        SELECT count(*) FROM pg_locks WHERE locktype IN ('transactionid', 'tuple') AND NOT granted
        SQL;

    private SmtpSink $sink;
    private Connection $connection;
    private MessageBusInterface $bus;
    private string $venueId;

    protected function setUp(): void
    {
        $this->sink = SmtpSink::start();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->bus = $bus;
        $pool = self::getContainer()->get('cache.inquiry_submit');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        // The two children are the suite's first renders of base.html.twig, and they render at
        // the same moment. On Docker Desktop's bind mount (virtiofs) `rename` is not atomic, so a
        // child can find an asset_mapper cache file the other is replacing, then fail to read it
        // (ENOENT inside asset(), a 500). Filling that cache here, before any child starts,
        // closes the window; the bootstrap's cache wipe would otherwise open it on every run.
        $assetMapper = self::getContainer()->get('asset_mapper');
        self::assertInstanceOf(AssetMapperInterface::class, $assetMapper);
        iterator_to_array($assetMapper->allAssets());

        $this->removeFixtures();
        $this->venueId = DetailVenues::full($em, self::SLUG, self::NAME)->id();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->connection->fetchOne('SELECT pg_advisory_unlock_all()');
        $this->removeFixtures();
        parent::tearDown();
        $this->sink->stop();
    }

    public function test_two_posts_at_once_store_one_row_and_send_one_pair_of_mails(): void
    {
        $lock = 'hashtextextended(?, 0)';
        $fingerprint = [$this->fingerprint()];
        $this->connection->fetchOne("SELECT pg_advisory_lock({$lock})", $fingerprint);

        $posts = $this->postTwice($this->sink->dsn);
        $this->waitForBoth(self::WAITING_FOR_THE_FINGERPRINT, $posts);
        $this->connection->fetchOne("SELECT pg_advisory_unlock({$lock})", $fingerprint);

        $this->assertStatusCodes([303, 303], $posts);
        self::assertSame([['sent', 'sent']], $this->statuses());
        self::assertSame([[self::VENUE_MAIL_RECIPIENT], [self::GUEST]], $this->sink->delivered());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_inquiry_links'), 'One row, one ingestion.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function undeliveredRows(): iterable
    {
        yield 'still pending' => ['pending', 'pending'];
        yield 'after a failed venue mail' => ['failed', 'skipped'];
    }

    #[DataProvider('undeliveredRows')]
    public function test_two_repeats_at_once_deliver_a_stored_row_once(string $venueMail, string $guestMail): void
    {
        $id = $this->storedRow($venueMail, $guestMail);
        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM inquiries WHERE id = ? FOR UPDATE', [$id]);

        $posts = $this->postTwice($this->sink->dsn);
        $this->waitForBoth(self::WAITING_FOR_THE_ROW, $posts);
        $this->connection->commit();

        $this->assertStatusCodes([303, 303], $posts);
        self::assertSame([['sent', 'sent']], $this->statuses());
        self::assertSame([[self::VENUE_MAIL_RECIPIENT], [self::GUEST]], $this->sink->delivered());
    }

    /**
     * Why a repeat waits instead of answering at once: the browser shows the second POST's
     * response, and that must not be a confirmation of a mail that never left.
     */
    public function test_a_repeat_is_not_confirmed_when_the_mail_fails(): void
    {
        $id = $this->storedRow('pending', 'pending');
        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM inquiries WHERE id = ? FOR UPDATE', [$id]);

        $posts = $this->postTwice(self::DEAD_DSN);
        $this->waitForBoth(self::WAITING_FOR_THE_ROW, $posts);
        $this->connection->commit();

        $this->assertStatusCodes([503, 503], $posts);
        self::assertSame([['failed', 'skipped']], $this->statuses());
    }

    /**
     * @return array{Process, Process}
     */
    private function postTwice(string $mailerDsn): array
    {
        return [$this->post($mailerDsn), $this->post($mailerDsn)];
    }

    private function post(string $mailerDsn): Process
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 2).'/Support/Inquiries/post-inquiry.php', self::SLUG, json_encode(InquiryFormFields::valid(), \JSON_THROW_ON_ERROR)],
            env: ['MAILER_DSN' => $mailerDsn],
            timeout: self::WAIT_SECONDS,
        );
        $process->start();

        return $process;
    }

    /**
     * @param array{Process, Process} $posts
     */
    private function waitForBoth(string $waitingQuery, array $posts): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (2 > (int) $this->connection->fetchOne($waitingQuery)) {
            foreach ($posts as $post) {
                self::assertTrue($post->isRunning(), 'A POST ended without waiting: '.$post->getOutput().$post->getErrorOutput());
            }
            self::assertLessThan($deadline, microtime(true), 'The POSTs never met.');
            usleep(10_000);
        }
    }

    /**
     * A child's stderr holds the kernel's log lines (ids and paths, no addresses), so a wrong
     * status names its own cause.
     *
     * @param list<int>               $expected
     * @param array{Process, Process} $posts
     */
    private function assertStatusCodes(array $expected, array $posts): void
    {
        $codes = [];
        $errors = '';
        foreach ($posts as $post) {
            $post->wait();
            self::assertSame(0, $post->getExitCode(), $post->getErrorOutput());
            $codes[] = (int) $post->getOutput();
            $errors .= $post->getErrorOutput();
        }

        self::assertSame($expected, $codes, "The children's stderr:\n".$errors);
    }

    private function command(): SubmitInquiry
    {
        $fields = InquiryFormFields::valid()['inquiry'];
        self::assertIsString($fields['message']);

        return new SubmitInquiry($this->venueId, self::SLUG, 'de', 'wedding', 120, null, $fields['message'], 'Anna', 'Muster', self::GUEST, '044 123 45 67');
    }

    private function fingerprint(): string
    {
        $secret = self::getContainer()->getParameter('kernel.secret');
        self::assertIsString($secret);

        return InquiryFingerprint::of($this->command(), $secret)->value;
    }

    private function storedRow(string $venueMail, string $guestMail): string
    {
        $this->bus->dispatch($this->command());
        $this->connection->executeStatement('UPDATE inquiries SET venue_mail_status = ?, guest_mail_status = ? WHERE venue_name = ?', [$venueMail, $guestMail, self::NAME]);
        $id = $this->connection->fetchOne('SELECT id FROM inquiries WHERE venue_name = ?', [self::NAME]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array{mixed, mixed}> venue mail and guest mail, per row
     */
    private function statuses(): array
    {
        $rows = $this->connection->fetchAllNumeric('SELECT venue_mail_status, guest_mail_status FROM inquiries WHERE venue_name = ?', [self::NAME]);

        return array_map(static fn (array $row): array => [$row[0], $row[1]], $rows);
    }

    private function removeFixtures(): void
    {
        // The children commit, so what `IngestInquiryListener` wrote stays too (the couple is no contact, #55). This goes
        // first: the inquiry's delete cascades to its link, the only way back to the entry.
        $this->connection->executeStatement(
            'DELETE FROM crm_activities WHERE id IN (SELECT l.activity_id FROM crm_inquiry_links l JOIN inquiries i ON i.id = l.inquiry_id WHERE i.venue_name = ?)',
            [self::NAME],
        );
        $this->connection->executeStatement('DELETE FROM inquiries WHERE venue_name = ?', [self::NAME]);
        // The deliveries committed funnel steps (G8) too; they name the venue, so they go before it.
        $this->connection->executeStatement('DELETE FROM funnel_events WHERE venue_id IN (SELECT id FROM venues WHERE name = ?)', [self::NAME]);
        $this->connection->executeStatement('DELETE FROM venues WHERE name = ?', [self::NAME]);
    }
}
