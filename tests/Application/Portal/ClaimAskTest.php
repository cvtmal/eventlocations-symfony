<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\RequestVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Crm\ForgedPosts;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class ClaimAskTest extends WebTestCase
{
    use Factories;
    use ForgedPosts;
    use PortalSession;

    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    public function test_asking_writes_a_pending_row_and_says_so(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne(['name' => 'Schloss Seeblick']);

        $crawler = $this->client->request('GET', '/konto/locations/suchen?q=seeblick');
        $this->client->submit($crawler->filter('form[action="/konto/locations/zugriff"]')->form());

        self::assertResponseRedirects('/konto/locations/suchen?q=seeblick', 303);
        self::assertSame([['status' => 'pending']], $this->accessRows($manager, $venue));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ihre Anfrage liegt bei uns.', $crawler->text());
        self::assertSame('Anfrage liegt bei uns', trim($crawler->filter('#claim-results .pill')->text()));
    }

    public function test_asking_mails_staff_at_the_contact_address(): void
    {
        $this->logInAsManager($this->createManager(attributes: ['email' => 'manager@example.ch', 'firstName' => 'Max', 'lastName' => 'Muster']));
        $venue = VenueFactory::createOne(['name' => 'Schloss Seeblick']);

        $this->ask($venue);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame([self::getContainer()->getParameter('site.contact_email')], array_map(static fn ($address): string => $address->getAddress(), $mail->getTo()));
        self::assertSame('Neue Zugriffsanfrage: Schloss Seeblick', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Max Muster', $html);
        self::assertStringContainsString('manager@example.ch', $html);
        self::assertStringContainsString('http://localhost/admin/pruefung?tab=requests', $html);
    }

    public function test_asking_again_mails_nobody(): void
    {
        $this->logInAsManager();
        $venue = VenueFactory::createOne();

        $this->ask($venue);
        $this->ask($venue);

        self::assertEmailCount(0);
    }

    public function test_a_failing_staff_mail_keeps_the_request(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $failed = false;
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event) use (&$failed): void {
            if ($event->isQueued()) {
                $failed = true;
                throw new TransportException('The mail server is gone.');
            }
        }, 50);

        $this->ask($venue);

        self::assertTrue($failed, 'The staff mail was never attempted.');
        self::assertEmailCount(0);

        self::assertResponseRedirects('/konto/locations/suchen', 303);
        self::assertSame([['status' => 'pending']], $this->accessRows($manager, $venue));
        self::assertStringContainsString('Ihre Anfrage liegt bei uns.', $this->client->followRedirect()->text());
    }

    public function test_asking_is_audited_as_the_portal_user(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne();

        $this->ask($venue);

        $row = $this->connection->fetchAssociative('SELECT actor_type, actor_id, portal_user_id, subject_type, subject_id FROM admin_audit_log WHERE command = ?', [RequestVenueAccess::class]);
        self::assertIsArray($row);
        self::assertSame('portal_user', $row['actor_type']);
        self::assertNull($row['actor_id']);
        self::assertSame($manager->id(), $row['portal_user_id']);
        self::assertSame('portal_user', $row['subject_type']);
        self::assertSame($manager->id(), $row['subject_id']);
    }

    public function test_asking_twice_changes_nothing_and_writes_no_second_audit_row(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne();

        $this->ask($venue);
        $this->ask($venue);

        self::assertResponseRedirects('/konto/locations/suchen', 303);
        self::assertSame([['status' => 'pending']], $this->accessRows($manager, $venue));
        self::assertSame(1, $this->auditCount());
        self::assertStringContainsString('Ihre Anfrage liegt schon bei uns', $this->client->followRedirect()->text());
    }

    public function test_a_location_with_a_manager_follows_the_same_path(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne();
        $other = PortalUserFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create(['user' => $other, 'venue' => $venue]);

        $this->ask($venue);

        self::assertSame([['status' => 'pending']], $this->accessRows($manager, $venue));
        self::assertSame([['status' => 'active']], $this->accessRows($other, $venue));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_bad_token_writes_nothing(array $token, array $server): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne();

        $this->client->request('POST', '/konto/locations/zugriff', $token + ['number' => (string) $venue->number()], server: $server);

        self::assertResponseRedirects('/konto/locations/suchen', 303);
        self::assertSame([], $this->accessRows($manager, $venue));
        self::assertSame(0, $this->auditCount());
        self::assertEmailCount(0);
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->text());
    }

    /**
     * @return iterable<string, array{array<string>|string}>
     */
    public static function malformedNumbers(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'zero' => ['0'];
        yield 'a leading zero' => ['01'];
        yield 'ten digits' => ['1234567890'];
        yield 'a trailing newline' => ["1\n"];
        yield 'an array' => [['1']];
    }

    /**
     * @param array<string>|string $number
     */
    #[DataProvider('malformedNumbers')]
    public function test_a_malformed_or_array_number_writes_nothing(array|string $number): void
    {
        $this->logInAsManager();

        $this->client->request('POST', '/konto/locations/zugriff', ['_token' => self::SAME_SITE_TOKEN, 'number' => $number], server: self::SAME_ORIGIN);

        self::assertResponseRedirects('/konto/locations/suchen', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM portal_venue_access'));
        self::assertSame(0, $this->auditCount());
    }

    public function test_an_unpublished_location_is_refused(): void
    {
        $manager = $this->logInAsManager();
        $venue = VenueFactory::createOne(['status' => VenueStatus::Unpublished]);

        $this->ask($venue);

        self::assertResponseRedirects('/konto/locations/suchen', 303);
        self::assertSame([], $this->accessRows($manager, $venue));
        self::assertEmailCount(0);
        self::assertStringContainsString('Diese Location ist nicht mehr verfügbar.', $this->client->followRedirect()->text());
    }

    private function ask(Venue $venue): void
    {
        $this->client->request('POST', '/konto/locations/zugriff', ['_token' => self::SAME_SITE_TOKEN, 'number' => (string) $venue->number()], server: self::SAME_ORIGIN);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accessRows(PortalUser $manager, Venue $venue): array
    {
        return $this->connection->fetchAllAssociative('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$manager->id(), $venue->id()]);
    }

    private function auditCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log WHERE command = ?', [RequestVenueAccess::class]);
    }
}
