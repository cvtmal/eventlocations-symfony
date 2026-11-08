<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\Event\ProRequested;
use App\Tests\Application\Crm\ForgedPosts;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Pro anfragen" (F12): a press on the plan card publishes `ProRequested` with the location and the manager, for the
 * CRM to turn into a task, and thanks the manager. It writes nothing itself. A location on Pro has nothing to ask for
 * (404), a bad token changes nothing, and another manager's location is refused like every `/konto` route.
 *
 * Most tests catch the event with a listener of their own, which proves the controller's side alone. One test lets the
 * CRM's listener run as it is wired and reads the task it leaves for staff.
 */
final class ProRequestTest extends WebTestCase
{
    use Factories;
    use ForgedPosts;
    use ImportsTownDataset;
    use PortalSession;

    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];

    /** @var list<ProRequested> */
    private array $requests = [];

    public function test_a_press_publishes_the_request_with_the_managers_details_and_says_thanks(): void
    {
        $manager = $this->createManager(attributes: ['email' => 'mara@example.ch', 'firstName' => 'Mara', 'lastName' => 'Muster']);
        $venue = $this->managedVenue(DetailVenues::minimal(), $manager);
        $this->recordRequests();

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->submit($crawler->filter('form[action$="/pro-anfragen"]')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->url($venue));
        self::assertEquals([new ProRequested($venue->id(), 'Gartenhaus Minimal', 'mara@example.ch', 'Mara', 'Muster')], $this->requests);
        self::assertSame('Danke! Wir haben deine Anfrage erhalten und melden uns bei dir.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_the_press_leaves_one_task_for_staff_however_often_it_is_pressed(): void
    {
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $manager = $this->createManager(attributes: ['email' => 'mara@example.ch', 'firstName' => 'Mara', 'lastName' => 'Muster']);
        $venue = $this->managedVenue(DetailVenues::minimal(), $manager);

        $this->ask($venue);
        $this->ask($venue);

        self::assertSame(
            ['Pro-Anfrage: Gartenhaus Minimal (mara@example.ch)'],
            $this->connection->fetchFirstColumn("SELECT title FROM crm_tasks WHERE title LIKE 'Pro-Anfrage:%'"),
        );
        self::assertSame(
            [$venue->id()],
            $this->connection->fetchFirstColumn("SELECT c.venue_id FROM crm_tasks t JOIN crm_companies c ON c.id = t.company_id WHERE t.title LIKE 'Pro-Anfrage:%'"),
        );
    }

    public function test_the_press_changes_nothing_about_the_location(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $audit = $this->venueAuditRows();

        $this->ask($venue);

        self::assertResponseRedirects($this->url($venue));
        self::assertSame(KnownTiers::FREE, $this->connection->fetchOne('SELECT t.code FROM venues v JOIN tiers t ON t.id = v.tier_id WHERE v.id = ?', [$venue->id()]));
        self::assertSame($audit, $this->venueAuditRows(), 'The CRM may note the request; the venue is not touched.');
        self::assertNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_a_second_press_is_harmless(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());

        $this->ask($venue);
        self::assertResponseRedirects($this->url($venue));
        $this->ask($venue);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->url($venue));
        self::assertSame('Danke! Wir haben deine Anfrage erhalten und melden uns bei dir.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_subscriber_that_fails_does_not_fail_the_press(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->dispatcher()->addListener(ProRequested::class, static function (): never {
            throw new \RuntimeException('The CRM is down.');
        });

        $this->ask($venue);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('Danke! Wir haben deine Anfrage erhalten und melden uns bei dir.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_location_on_pro_has_nothing_to_ask_for(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal(tier: KnownTiers::PRO));
        $this->recordRequests();

        $this->ask($venue);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requests);
    }

    public function test_without_a_pro_tier_there_is_nothing_to_ask_for(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->connection->executeStatement("DELETE FROM tiers WHERE code = 'pro'");
        $this->recordRequests();

        $this->ask($venue);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->requests);
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_bad_token_publishes_nothing_and_says_so(array $token, array $server): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->recordRequests();

        $this->client->request('POST', $this->url($venue).'/pro-anfragen', $token, server: $server);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($this->url($venue));
        self::assertSame([], $this->requests);
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_an_array_instead_of_the_token_is_a_bad_token_and_not_a_400(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());
        $this->recordRequests();

        $this->client->request('POST', $this->url($venue).'/pro-anfragen', ['_token' => ['csrf-token']], server: self::SAME_ORIGIN);

        self::assertResponseRedirects($this->url($venue));
        self::assertSame([], $this->requests);
    }

    public function test_another_managers_location_is_refused(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $this->logInAsManager();
        $this->recordRequests();

        $this->ask($venue);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->requests);
    }

    public function test_a_pending_request_for_access_is_no_access(): void
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::createOne(['user' => $this->logInAsManager(), 'venue' => $venue]);
        $this->recordRequests();

        $this->ask($venue);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->requests);
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $venue = DetailVenues::minimal();
        $this->recordRequests();

        $this->ask($venue);

        self::assertResponseRedirects('/konto/anmelden');
        self::assertSame([], $this->requests);
    }

    public function test_a_get_is_not_allowed(): void
    {
        $venue = $this->managedVenue(DetailVenues::minimal());

        $this->client->request('GET', $this->url($venue).'/pro-anfragen');

        self::assertResponseStatusCodeSame(405);
    }

    private function managedVenue(Venue $venue, ?PortalUser $manager = null): Venue
    {
        PortalVenueAccessFactory::new()->active()->create(['user' => $this->logInAsManager($manager), 'venue' => $venue]);

        return $venue;
    }

    private function ask(Venue $venue): void
    {
        $this->client->request('POST', $this->url($venue).'/pro-anfragen', ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
    }

    private function recordRequests(): void
    {
        $this->dispatcher()->addListener(ProRequested::class, function (ProRequested $event): void {
            $this->requests[] = $event;
        });
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function venueAuditRows(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue'");
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number();
    }
}
