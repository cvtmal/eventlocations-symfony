<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Factory\LastMinuteOfferFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:last-minute:expire` (G14, Wave G §4a.5, done-when "expiry tests"): at 03:15 Zurich it deletes the offers whose
 * last day was yesterday, keeps today's, mails each active manager of a Pro venue once per deleted offer, and the
 * second run writes and sends nothing. `--dry-run` counts and writes nothing.
 */
final class ExpireLastMinuteOffersCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use RollsBackDatabase {
        setUp as rollBackSetUp;
    }

    protected function setUp(): void
    {
        $this->rollBackSetUp();
        self::mockTime('2026-11-08 03:15:00 Europe/Zurich');
    }

    public function test_yesterdays_offer_is_deleted_and_todays_kept(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $gone = $this->offer($venue, '2026-11-05', '2026-11-07');
        $kept = $this->offer($venue, '2026-11-08', '2026-11-08');

        [$status, $output] = $this->expire();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 offers deleted.', trim($output));
        self::assertSame([$kept], $this->connection->fetchFirstColumn('SELECT id FROM last_minute_offers'));
        self::assertNotContains($gone, $this->connection->fetchFirstColumn('SELECT id FROM last_minute_offers'));
    }

    public function test_the_second_run_writes_nothing(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->offer($venue, '2026-11-07', '2026-11-07');
        $this->expire();
        self::assertEmailCount(1);
        $audit = (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log');

        [$status, $output] = $this->expire();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('0 offers deleted.', trim($output));
        self::assertEmailCount(1);
        self::assertSame($audit, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log'));
    }

    public function test_the_dry_run_counts_and_writes_nothing(): void
    {
        $venue = $this->venue(KnownTiers::PRO);
        $this->offer($venue, '2026-11-01', '2026-11-02');
        $this->offer($venue, '2026-11-07', '2026-11-07');
        $this->offer($venue, '2026-11-09', '2026-11-09');

        [$status, $output] = $this->expire(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('2 offers to delete.', trim($output));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM last_minute_offers'));
        self::assertEmailCount(0);
    }

    public function test_a_good_night_under_quiet_prints_nothing(): void
    {
        $this->offer($this->venue(KnownTiers::PRO), '2026-11-07', '2026-11-07');

        [$status, $output] = $this->expire([], OutputInterface::VERBOSITY_QUIET);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('', $output);
    }

    public function test_each_active_manager_of_a_pro_venue_gets_one_mail_in_du(): void
    {
        $venue = $this->venue(KnownTiers::PRO, 'Seehaus Meilen');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->manager($venue, 'beat@example.ch', 'Beat');
        $this->manager($venue, 'weg@example.ch', 'Weg', active: false);
        $this->offer($venue, '2026-11-06', '2026-11-07');

        $this->expire();

        self::assertEmailCount(2);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('anna@example.ch', $mail->getTo()[0]->getAddress());
        self::assertSame('Dein Angebot «Kurzfristig frei» für Seehaus Meilen ist abgelaufen', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Hallo Anna', $html);
        self::assertStringContainsString('06.11.2026 bis 07.11.2026', $html);
        self::assertStringContainsString('/konto/locations/'.$venue->number().'/kurzfristig-frei', $html);
        self::assertStringContainsString('Neues Angebot erfassen', $html);
        $text = (string) $mail->getTextBody();
        self::assertStringContainsString('Hallo Anna', $text);
        self::assertStringNotContainsString('Sie ', $text);
        self::assertStringNotContainsString('ß', $text);
    }

    public function test_a_free_venue_or_one_without_managers_gets_no_mail(): void
    {
        $free = $this->venue(KnownTiers::FREE);
        $this->manager($free, 'anna@example.ch', 'Anna');
        $this->offer($free, '2026-11-07', '2026-11-07');
        $this->offer($this->venue(KnownTiers::PRO), '2026-11-07', '2026-11-07');

        [, $output] = $this->expire();

        self::assertSame('2 offers deleted.', trim($output));
        self::assertEmailCount(0);
    }

    private function venue(string $tier, string $name = 'Testhaus'): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['tier' => $tier, 'name' => $name]);
    }

    private function offer(Venue $venue, string $startsOn, string $endsOn): string
    {
        return LastMinuteOfferFactory::createOne(['venue' => $venue, 'startsOn' => new \DateTimeImmutable($startsOn), 'endsOn' => new \DateTimeImmutable($endsOn)])->id();
    }

    private function manager(Venue $venue, string $email, string $firstName, bool $active = true): void
    {
        $user = PortalUserFactory::createOne(['email' => $email, 'firstName' => $firstName]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);
        if (!$active) {
            $this->connection->executeStatement('UPDATE portal_users SET is_active = false WHERE id = ?', [$user->id()]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function expire(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:last-minute:expire'));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
