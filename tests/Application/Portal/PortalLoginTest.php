<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Crm\CountsQueries;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The manager's login: form_login behind the `portal` firewall, with the two refusals of a right password.
 */
final class PortalLoginTest extends WebTestCase
{
    use CountsQueries;
    use Factories;
    use PortalSession;

    private const string INVALID = 'E-Mail oder Passwort ist falsch.';

    public function test_a_login_lands_on_the_account(): void
    {
        $this->logInAsManager();

        self::assertResponseRedirects('/konto');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Meine Locations');
    }

    public function test_the_account_shows_who_is_logged_in_and_that_nothing_is_linked(): void
    {
        $this->logInAsManager($this->createManager(attributes: ['firstName' => 'Mara', 'lastName' => 'Frei']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sidebar__name', 'Mara Frei');
        self::assertSelectorTextContains('.sidebar__avatar', 'MF');
        self::assertSelectorTextContains('.empty__text', 'noch keine Location');
        self::assertSelectorExists('.sidebar__item--active[aria-current="page"]');
    }

    public function test_the_account_lists_the_manager_s_active_locations_only(): void
    {
        $manager = $this->logInAsManager();
        $published = VenueFactory::createOne(['name' => 'Schloss Aarau', 'tier' => Tier::Premium]);
        $offline = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Waldhütte Eichholz', 'status' => VenueStatus::Unpublished]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $published]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $offline]);
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => VenueFactory::createOne(['name' => 'Scheune Beantragt'])]);
        PortalVenueAccessFactory::new()->active()->create(['venue' => VenueFactory::createOne(['name' => 'Fremdes Gut'])]);

        $crawler = $this->client->request('GET', '/konto');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.empty__text');
        $links = $crawler->filter('.venue-widget__name');
        self::assertSame(['Schloss Aarau', 'Waldhütte Eichholz'], $links->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('/konto/locations/'.$offline->number(), $links->eq(1)->attr('href'));
        $row = $crawler->filter('.venue-widget__row')->eq(1);
        self::assertSame(['Unpubliziert', 'Keine Fotos', 'Keine Anfrage-E-Mail', 'Kurztext fehlt', 'Haupt-Gebäudeart fehlt'], $row->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text()));
        self::assertStringNotContainsString('Premium', $crawler->filter('.venue-widget')->text());
    }

    public function test_the_list_costs_one_statement_more_than_the_empty_account(): void
    {
        $manager = $this->logInAsManager();
        $empty = $this->queriesOf('/konto');

        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => VenueFactory::createOne()]);

        self::assertSame($empty + 1, $this->queriesOf('/konto'));
    }

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/konto');

        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->submitManagerLogin($this->createManager()->email(), 'wrong-password');

        self::assertResponseRedirects('/konto/anmelden');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#login-error', self::INVALID);
    }

    public function test_the_sixth_attempt_is_throttled(): void
    {
        $email = $this->createManager()->email();
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitManagerLogin($email, 'wrong-password');
            $this->client->followRedirect();
            self::assertSelectorTextContains('#login-error', self::INVALID);
        }

        $this->submitManagerLogin($email, 'wrong-password');
        $this->client->followRedirect();

        self::assertSelectorTextContains('#login-error', 'Zu viele Versuche.');
    }

    public function test_a_deactivated_account_learns_why_only_with_the_right_password(): void
    {
        $manager = $this->createManager(active: false);

        $this->submitManagerLogin($manager->email());
        self::assertResponseRedirects('/konto/anmelden');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#login-error', 'Dieses Konto ist deaktiviert.');

        $this->submitManagerLogin($manager->email(), 'wrong-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#login-error', self::INVALID);

        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_an_account_with_no_password_gets_the_ordinary_invalid_answer(bool $active): void
    {
        $manager = $this->createManager(confirmed: false, active: $active);

        $this->submitManagerLogin($manager->email());
        self::assertResponseRedirects('/konto/anmelden');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#login-error', self::INVALID);

        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_logged_in_manager_skips_the_login_page(): void
    {
        $this->logInAsManager();

        $this->client->request('GET', '/konto/anmelden');

        self::assertResponseRedirects('/konto');
    }

    public function test_get_on_logout_is_not_allowed(): void
    {
        $this->client->request('GET', '/konto/abmelden');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_logout_ends_the_session(): void
    {
        $this->logInAsManager();
        $page = $this->client->followRedirect();

        $this->client->submit($page->filter('.sidebar__logout-form')->form());

        self::assertResponseRedirects('/konto/anmelden');
        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_deactivating_ends_a_running_session(): void
    {
        $manager = $this->logInAsManager();
        $this->client->request('GET', '/konto');
        self::assertResponseIsSuccessful();

        $em = $this->portalEntityManager();
        $em->find(PortalUser::class, $manager->id())?->deactivate();
        $em->flush();

        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_login_is_recorded(): void
    {
        $manager = $this->createManager();
        self::assertNull($this->lastLoginAt($manager->id()));

        $this->logInAsManager($manager);

        self::assertNotNull($this->lastLoginAt($manager->id()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function brokenUsernames(): iterable
    {
        yield 'NUL byte' => ["manager\0@example.test"];
        yield 'invalid UTF-8' => ["manager\xFF@example.test"];
    }

    #[DataProvider('brokenUsernames')]
    public function test_a_broken_username_is_an_ordinary_refusal(string $username): void
    {
        $this->submitManagerLogin($username, 'wrong-password');

        self::assertResponseRedirects('/konto/anmelden');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#login-error', self::INVALID);
    }

    private function lastLoginAt(string $id): mixed
    {
        return $this->connection->fetchOne('SELECT last_login_at FROM portal_users WHERE id = ?', [$id]);
    }
}
