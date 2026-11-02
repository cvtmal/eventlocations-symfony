<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\PortalUserFactory;
use App\Portal\Command\DeactivatePortalUser;
use App\Portal\Entity\PortalUser;
use App\Portal\ReadModel\PortalAccountListQuery;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The staff screen for manager accounts (POR-3). `ChecksAdminRoutes` walks its routes with every other /admin route.
 */
final class PortalAccountsTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
    }
    use Factories;

    private const string UNKNOWN_ID = '0199f0d0-0000-7000-8000-000000000001';

    public function test_the_list_shows_the_newest_account_first_with_its_state(): void
    {
        $this->account('Nora', 'Keller', 'nora@example.test', '2026-10-03 10:00:00+02', ['phone' => '+41 44 123 45 67']);
        $this->account('Beat', 'Meier', 'beat@example.test', '2026-10-02 10:00:00+02', ['email_verified_at' => null]);
        $this->account('Sara', 'Frei', 'sara@example.test', '2026-10-01 10:00:00+02', ['is_active' => 'false']);
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/konten');

        self::assertResponseIsSuccessful();
        self::assertSame(['Nora Keller', 'Beat Meier', 'Sara Frei'], $this->names($crawler));
        self::assertSame(['Aktiv', 'Unbestätigt', 'Deaktiviert'], $crawler->filter('#account-list .pill')->each(static fn (Crawler $pill): string => $pill->text()));
        self::assertSame(['Deaktivieren', 'Deaktivieren', 'Reaktivieren'], $crawler->filter('#account-list .table__actions button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertStringContainsString('+41 44 123 45 67', $crawler->filter('#account-list')->text());
        self::assertStringContainsString('Noch nie', $crawler->filter('#account-list')->text());
        self::assertSame('Konten', $crawler->filter('.sidebar__item[aria-current="page"]')->text());
    }

    public function test_the_search_goes_over_name_and_address(): void
    {
        $this->account('Jürg', 'Müller', 'juerg@example.test', '2026-10-02 10:00:00+02');
        $this->account('Nora', 'Keller', 'buchung@schloss.example', '2026-10-01 10:00:00+02');
        $this->logInAs();

        foreach (['muell', 'MÜLLER', 'Jürg M'] as $q) {
            self::assertSame(['Jürg Müller'], $this->names($this->client->request('GET', '/admin/konten', ['q' => $q])), $q);
        }
        self::assertSame(['Nora Keller'], $this->names($this->client->request('GET', '/admin/konten', ['q' => 'buchung@schloss'])));

        $crawler = $this->client->request('GET', '/admin/konten', ['q' => '!!!']);
        self::assertSame('Keine Konten gefunden', $crawler->filter('.empty__title')->text());
    }

    public function test_the_list_pages(): void
    {
        PortalUserFactory::createMany(PortalAccountListQuery::PER_PAGE + 1);
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/konten');
        self::assertCount(PortalAccountListQuery::PER_PAGE, $this->names($crawler));

        $crawler = $this->client->click($crawler->filter('a[rel="next"]')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->names($crawler));
    }

    public function test_a_search_with_a_nul_byte_gives_every_account_with_a_notice(): void
    {
        $this->account('Nora', 'Keller', 'nora@example.test', '2026-10-01 10:00:00+02');
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/konten', ['q' => "no\0ra"]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['Nora Keller'], $this->names($crawler));
        self::assertSame(1, $crawler->filter('.form-error')->count());
    }

    public function test_an_editor_is_refused(): void
    {
        $manager = $this->createManager();
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/konten');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->post('/admin/konten/'.$manager->id().'/deaktivieren', 'deactivate');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertTrue($this->isActive($manager));
    }

    public function test_deactivating_is_audited_and_ends_the_running_session(): void
    {
        $manager = $this->logInAsManager();
        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();

        $staff = $this->logInAs();
        $this->client->request('GET', '/admin/konten', ['q' => $manager->email()]);
        $this->client->submitForm('Deaktivieren');

        self::assertResponseRedirects('/admin/konten');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Das Konto ist deaktiviert.');
        self::assertFalse($this->isActive($manager));

        self::assertSame([
            'actor_type' => 'admin_user',
            'actor_id' => $staff['user']->id(),
            'portal_user_id' => null,
            'subject_id' => $manager->id(),
            'changes' => '{"is_active": false}',
        ], $this->connection->fetchAssociative(
            'SELECT actor_type, actor_id, portal_user_id, subject_id, changes::text AS changes FROM admin_audit_log WHERE subject_type = ? AND command = ?',
            ['portal_user', DeactivatePortalUser::class],
        ));

        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_reactivating_lets_the_manager_log_in_again(): void
    {
        $manager = $this->createManager(active: false);
        $this->submitManagerLogin($manager->email());
        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');

        $this->logInAs();
        $this->client->request('GET', '/admin/konten', ['q' => $manager->email()]);
        $this->client->submitForm('Reaktivieren');
        self::assertResponseRedirects('/admin/konten');
        self::assertTrue($this->isActive($manager));

        $this->logInAsManager($manager);
        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();
    }

    public function test_an_unknown_account_is_not_found(): void
    {
        $this->logInAs();

        $this->post('/admin/konten/'.self::UNKNOWN_ID.'/deaktivieren', 'deactivate', ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_a_post_without_csrf_evidence_changes_nothing(): void
    {
        $manager = $this->createManager();
        $this->logInAs();

        $this->post('/admin/konten/'.$manager->id().'/deaktivieren', 'deactivate');

        self::assertResponseRedirects('/admin/konten');
        self::assertTrue($this->isActive($manager));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Das hat nicht geklappt.');
    }

    /**
     * @param array<string, ?string> $columns
     */
    private function account(string $firstName, string $lastName, string $email, string $createdAt, array $columns = []): void
    {
        $user = PortalUserFactory::createOne(['firstName' => $firstName, 'lastName' => $lastName, 'email' => $email]);
        $this->connection->update('portal_users', ['created_at' => $createdAt, ...$columns], ['id' => $user->id()]);
    }

    /**
     * @param array<string, string> $server
     */
    private function post(string $path, string $formName, array $server = []): void
    {
        $this->client->request('POST', $path, [$formName => ['_token' => 'csrf-token']], server: $server);
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#account-list tbody td:first-child:not(.table__empty)')->each(static fn (Crawler $cell): string => $cell->text());
    }

    private function isActive(PortalUser $manager): bool
    {
        return (bool) $this->connection->fetchOne('SELECT is_active FROM portal_users WHERE id = ?', [$manager->id()]);
    }
}
