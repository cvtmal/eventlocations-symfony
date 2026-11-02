<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\ValueObject\AdminRole;
use App\Factory\PortalUserFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class AuditLogScreenTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_editor_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_admin_sees_a_row_with_its_actor(): void
    {
        $this->logInAs(displayName: 'Andrea Keller');
        $this->createUserOnScreen('neu@example.ch');

        $crawler = $this->client->request('GET', '/admin/audit-log');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tbody tr')->text();
        self::assertStringContainsString('Andrea Keller', $row);
        self::assertStringContainsString('CreateAdminUser', $row);
        self::assertStringContainsString('neu@example.ch', $row);
        self::assertStringContainsString('admin_user', $row);
    }

    public function test_a_manager_shows_by_name_and_a_deleted_one_as_deleted(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::createOne(['firstName' => 'Nora', 'lastName' => 'Meier']);
        foreach ([$manager->id(), null] as $portalUserId) {
            $this->connection->insert('admin_audit_log', [
                'id' => EntityId::generate(),
                'actor_type' => 'portal_user',
                'portal_user_id' => $portalUserId,
                'command' => 'App\Portal\Command\UpdatePortalProfile',
                'subject_type' => 'portal_user',
                'subject_id' => $manager->id(),
                'changes' => '{}',
            ]);
        }

        $crawler = $this->client->request('GET', '/admin/audit-log');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody')->text();
        self::assertStringContainsString('Nora Meier', $rows);
        self::assertStringContainsString('Gelöschtes Konto', $rows);
    }

    public function test_filters_narrow_the_list(): void
    {
        $staff = $this->logInAs();
        $this->createUserOnScreen('neu@example.ch');
        $this->createUserOnScreen('zweit@example.ch');
        $subject = $this->connection->fetchOne("SELECT id FROM admin_users WHERE email = 'zweit@example.ch'");
        self::assertIsString($subject);

        self::assertCount(2, $this->rows('/admin/audit-log?actor='.$staff['user']->id()));
        self::assertCount(1, $this->rows('/admin/audit-log?subject_type=admin_user&subject_id='.$subject));
        self::assertCount(0, $this->rows('/admin/audit-log?subject_type=venue'));
        self::assertCount(2, $this->rows('/admin/audit-log?from=2020-01-01'));
        self::assertCount(0, $this->rows('/admin/audit-log?to=2020-01-01'));
    }

    public function test_the_origin_filter_narrows_to_manager_rows(): void
    {
        $this->logInAs();
        $this->createUserOnScreen('neu@example.ch');
        $manager = PortalUserFactory::createOne(['firstName' => 'Nora', 'lastName' => 'Meier']);
        $this->connection->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'portal_user',
            'portal_user_id' => $manager->id(),
            'command' => 'App\Venues\Command\ChangeVenueCapacity',
            'subject_type' => 'venue',
            'subject_id' => EntityId::generate(),
            'changes' => '{}',
        ]);

        $crawler = $this->client->request('GET', '/admin/audit-log?actor_type=portal_user');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Nora Meier', $rows->text());
        self::assertSame('Location-Konto', $crawler->filter('select[name="actor_type"] option[selected]')->text());
        self::assertCount(1, $this->rows('/admin/audit-log?actor_type=admin_user'));
    }

    public function test_a_bad_origin_does_not_break_the_page(): void
    {
        $this->logInAs();
        $this->createUserOnScreen('neu@example.ch');

        $crawler = $this->client->request('GET', '/admin/audit-log?actor_type=somebody');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function test_garbage_filters_do_not_break_the_page(): void
    {
        $this->logInAs();
        $this->createUserOnScreen('neu@example.ch');

        $crawler = $this->client->request('GET', '/admin/audit-log?actor=not-a-uuid&from=gestern');

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    public function test_a_huge_page_number_does_not_break_the_page(): void
    {
        $this->logInAs();
        $this->createUserOnScreen('neu@example.ch');

        $crawler = $this->client->request('GET', '/admin/audit-log?page=9223372036854775807');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    private function createUserOnScreen(string $email): void
    {
        $this->client->request('GET', '/admin/benutzer/neu');
        $this->client->submitForm('Erfassen', [
            'create_admin_user[email]' => $email,
            'create_admin_user[displayName]' => 'Nora Neu',
            'create_admin_user[role]' => 'editor',
            'create_admin_user[plainPassword][first]' => 'a-long-enough-password',
            'create_admin_user[plainPassword][second]' => 'a-long-enough-password',
        ]);
        self::assertResponseRedirects();
    }

    /**
     * @return list<Crawler>
     */
    private function rows(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('tbody tr')->each(static fn (Crawler $row): Crawler => $row);
    }
}
