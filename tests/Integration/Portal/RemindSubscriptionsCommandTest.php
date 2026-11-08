<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubscriptionFactory;
use App\Factory\VenueFactory;
use App\Portal\Security\SubscriptionCancelToken;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Subscription;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:subscriptions:remind` (G9, Wave G §4a.5, §4a.6), driven by the clock: the 30-day and the 7-day reminder go once
 * each to the location's active managers (or its inquiry address), in du, through the mail layout, with the price and
 * the cancellation link; the second run sends nothing; a cancelled or renewed term gets none.
 */
final class RemindSubscriptionsCommandTest extends KernelTestCase
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

    public function test_the_30_day_reminder_goes_to_the_managers_once(): void
    {
        $venue = $this->venue('Seehaus Meilen');
        $term = $this->term($venue, '2026-12-01');
        $this->manager($venue, 'anna@example.ch', 'Anna');
        $this->manager($venue, 'beat@example.ch', 'Beat');
        $this->manager($venue, 'weg@example.ch', 'Weg', active: false);

        [$status, $output] = $this->remind();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('1 reminders sent, 0 skipped, 0 failed.', trim($output));
        self::assertEmailCount(2);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('anna@example.ch', $mail->getTo()[0]->getAddress());
        self::assertSame('Dein Tarif Pro für «Seehaus Meilen» läuft am 1. Dezember 2026 ab', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        foreach (['Hallo Anna', 'CHF 199 pro Jahr', '6 statt 60 sichtbar', 'werden nicht mehr angezeigt', 'Nicht verlängern'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('MwSt', $html, 'D24: no VAT line while tiers.vat_line is off');
        self::assertStringContainsString('/konto/abo-kuendigen/'.$this->token($term), $html);
        self::assertStringContainsString('Hallo Anna', (string) $mail->getTextBody());
        self::assertStringNotContainsString('Sie ', (string) $mail->getTextBody());
        self::assertSame([30], $this->markers($term));

        self::assertSame('0 reminders sent, 0 skipped, 0 failed.', trim($this->remind()[1]));
        self::assertEmailCount(2);
    }

    public function test_the_7_day_reminder_follows_and_a_late_term_gets_only_that_one(): void
    {
        $early = $this->term($this->manager($this->venue('Früh'))['venue'], '2026-11-30');
        $late = $this->term($this->manager($this->venue('Spät'))['venue'], '2026-11-12');

        self::assertSame('2 reminders sent, 0 skipped, 0 failed.', trim($this->remind()[1]));
        self::assertSame([30], $this->markers($early));
        self::assertSame([7], $this->markers($late));
        $subjects = array_map(static fn ($mail): string => $mail instanceof Email ? (string) $mail->getSubject() : '', self::getMailerMessages());
        self::assertContains('Dein Tarif Pro für «Spät» endet am 12. November 2026', $subjects);

        self::mockTime('2026-11-23 03:15:00 Europe/Zurich');
        $this->remind();
        self::assertSame([7, 30], $this->markers($early));
    }

    public function test_a_cancelled_or_renewed_term_gets_no_reminder(): void
    {
        $cancelled = $this->manager($this->venue('Gekündigt'))['venue'];
        SubscriptionFactory::new()->cancelled()->create(['venue' => $cancelled, 'startsOn' => new \DateTimeImmutable('2025-12-01'), 'endsOn' => new \DateTimeImmutable('2026-11-30')]);
        $renewed = $this->manager($this->venue('Verlängert'))['venue'];
        $this->term($renewed, '2026-11-30');
        SubscriptionFactory::createOne(['venue' => $renewed, 'startsOn' => new \DateTimeImmutable('2026-12-01'), 'endsOn' => new \DateTimeImmutable('2027-11-30')]);

        self::assertSame('0 reminders sent, 0 skipped, 0 failed.', trim($this->remind()[1]));
        self::assertEmailCount(0);
    }

    public function test_without_a_manager_the_inquiry_address_gets_it_and_without_any_address_it_waits(): void
    {
        $withInquiry = $this->venue('Mit Adresse', 'anfrage@example.ch');
        $this->term($withInquiry, '2026-11-30');
        $nobody = $this->venue('Ohne Adresse');
        $silent = $this->term($nobody, '2026-11-30');

        [, $output] = $this->remind();

        self::assertSame('1 reminders sent, 1 skipped, 0 failed.', trim($output));
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('anfrage@example.ch', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('>Hallo<', (string) $mail->getHtmlBody());
        self::assertSame([], $this->markers($silent), 'no marker: the next night tries again');
    }

    public function test_a_good_night_under_quiet_prints_nothing(): void
    {
        $this->term($this->manager($this->venue('Leise'))['venue'], '2026-11-30');

        self::assertSame('1 reminders due.', trim($this->remind(['--dry-run' => true])[1]));
        self::assertEmailCount(0);
        self::assertSame('', $this->remind([], OutputInterface::VERBOSITY_QUIET)[1]);
        self::assertEmailCount(1);
    }

    private function venue(string $name, ?string $inquiryEmail = null): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['name' => $name]);
        if (null !== $inquiryEmail) {
            $this->connection->executeStatement('UPDATE venues SET inquiry_email = ? WHERE id = ?', [$inquiryEmail, $venue->id()]);
        }

        return $venue;
    }

    private function term(Venue $venue, string $endsOn): Subscription
    {
        $end = new \DateTimeImmutable($endsOn);

        return SubscriptionFactory::createOne(['venue' => $venue, 'startsOn' => $end->modify('-1 year')->modify('+1 day'), 'endsOn' => $end]);
    }

    /**
     * @return array{venue: Venue}
     */
    private function manager(Venue $venue, ?string $email = null, string $firstName = 'Anna', bool $active = true): array
    {
        $user = PortalUserFactory::createOne(['email' => $email ?? strtolower($venue->number().'@example.ch'), 'firstName' => $firstName]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $user, 'venue' => $venue]);
        if (!$active) {
            $this->connection->executeStatement('UPDATE portal_users SET is_active = false WHERE id = ?', [$user->id()]);
        }

        return ['venue' => $venue];
    }

    /**
     * @return list<int>
     */
    private function markers(Subscription $term): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT days_before FROM portal_subscription_reminders WHERE subscription_id = ? ORDER BY days_before', [$term->id()]));
    }

    private function token(Subscription $term): string
    {
        $tokens = self::getContainer()->get(SubscriptionCancelToken::class);
        self::assertInstanceOf(SubscriptionCancelToken::class, $tokens);

        return $tokens->forId($term->id());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function remind(array $input = [], int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:subscriptions:remind'));
        $status = $tester->execute($input, ['verbosity' => $verbosity]);

        return [$status, $tester->getDisplay()];
    }
}
