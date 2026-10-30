<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class UsersScreenTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string UUID = '0199f0d0-0000-7000-8000-000000000001';

    public function test_an_editor_is_refused_on_every_route(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach ([
            ['GET', '/admin/benutzer'],
            ['GET', '/admin/benutzer/neu'],
            ['POST', '/admin/benutzer/neu'],
            ['GET', '/admin/benutzer/'.self::UUID],
            ['POST', '/admin/benutzer/'.self::UUID],
            ['POST', '/admin/benutzer/'.self::UUID.'/passwort'],
            ['POST', '/admin/benutzer/'.self::UUID.'/deaktivieren'],
            ['POST', '/admin/benutzer/'.self::UUID.'/reaktivieren'],
            ['POST', '/admin/benutzer/'.self::UUID.'/2fa-zuruecksetzen'],
        ] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    public function test_the_list_shows_every_user(): void
    {
        $this->logInAs(displayName: 'Andrea Keller');
        $this->createStaff(AdminRole::Editor, enrolled: false, displayName: 'Zoe Zweit');

        $crawler = $this->client->request('GET', '/admin/benutzer');

        self::assertResponseIsSuccessful();
        self::assertSame(['Andrea Keller', 'Zoe Zweit'], $crawler->filter('tbody tr td:first-child')->each(static fn (Crawler $cell): string => $cell->text()));
        $zoe = $crawler->filter('tbody tr')->eq(1)->text();
        self::assertStringContainsString('Redaktion', $zoe);
        self::assertStringContainsString('Ausstehend', $zoe);
        self::assertStringContainsString('Aktiv', $zoe);
        self::assertStringContainsString('Noch nie', $zoe);
    }

    public function test_creating_a_user_writes_an_audit_row_with_the_actor(): void
    {
        $staff = $this->logInAs();

        $this->client->request('GET', '/admin/benutzer/neu');
        $this->client->submitForm('Erfassen', [
            'create_admin_user[email]' => 'Neu@Example.ch',
            'create_admin_user[displayName]' => 'Nora Neu',
            'create_admin_user[role]' => 'editor',
            'create_admin_user[plainPassword][first]' => 'a-long-enough-password',
            'create_admin_user[plainPassword][second]' => 'a-long-enough-password',
        ]);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Der Benutzer ist erfasst.');

        $row = $this->connection->fetchAssociative(
            "SELECT l.actor_type, l.actor_id, l.changes FROM admin_audit_log l JOIN admin_users u ON u.id::text = l.subject_id WHERE u.email = 'neu@example.ch'",
        );
        self::assertIsArray($row);
        self::assertSame('admin_user', $row['actor_type']);
        self::assertSame($staff['user']->id(), $row['actor_id']);
        self::assertIsString($row['changes']);
        self::assertStringNotContainsString('a-long-enough-password', $row['changes']);
    }

    public function test_a_taken_email_is_a_field_error(): void
    {
        $staff = $this->logInAs();

        $this->client->request('GET', '/admin/benutzer/neu');
        $this->client->submitForm('Erfassen', [
            'create_admin_user[email]' => $staff['user']->email(),
            'create_admin_user[displayName]' => 'Zweite',
            'create_admin_user[role]' => 'editor',
            'create_admin_user[plainPassword][first]' => 'a-long-enough-password',
            'create_admin_user[plainPassword][second]' => 'a-long-enough-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#create_admin_user_email_error', 'Diese E-Mail-Adresse wird schon verwendet.');
        self::assertSelectorExists('#create_admin_user_email[aria-describedby="create_admin_user_email_error"]');
    }

    public function test_input_errors_are_linked_to_their_fields(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/benutzer/neu');
        $this->client->submitForm('Erfassen', [
            'create_admin_user[email]' => 'kein-mail',
            'create_admin_user[displayName]' => '',
            'create_admin_user[role]' => 'editor',
            'create_admin_user[plainPassword][first]' => 'kurz',
            'create_admin_user[plainPassword][second]' => 'anders',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#create_admin_user_email[aria-invalid="true"]');
        self::assertSelectorExists('#create_admin_user_displayName_error');
        self::assertSelectorTextContains('#create_admin_user_plainPassword_first_error', 'Die beiden Passwörter stimmen nicht überein.');
    }

    public function test_changing_a_role(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);

        $this->client->request('GET', '/admin/benutzer/'.$other['user']->id());
        $this->client->submitForm('Speichern', ['change_admin_role[role]' => 'admin']);

        self::assertResponseRedirects();
        self::assertSame('admin', $this->connection->fetchOne('SELECT role FROM admin_users WHERE id = ?', [$other['user']->id()]));
    }

    public function test_nobody_demotes_themselves(): void
    {
        $staff = $this->logInAs();

        $this->client->request('GET', '/admin/benutzer/'.$staff['user']->id());
        $this->client->submitForm('Speichern', ['change_admin_role[role]' => 'editor']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-error', 'Das eigene Konto kannst du hier nicht ändern.');
        self::assertSame('admin', $this->connection->fetchOne('SELECT role FROM admin_users WHERE id = ?', [$staff['user']->id()]));
    }

    public function test_nobody_deactivates_themselves(): void
    {
        $staff = $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/benutzer/'.$staff['user']->id());
        $this->client->submit($crawler->selectButton('Deaktivieren')->form());

        $crawler = $this->client->followRedirect();
        self::assertSame('Das eigene Konto kannst du hier nicht ändern.', $crawler->filter('.toast--error .toast__message')->text());
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM admin_users WHERE id = ?', [$staff['user']->id()]));
    }

    public function test_deactivate_and_reactivate(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);
        $url = '/admin/benutzer/'.$other['user']->id();

        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->selectButton('Deaktivieren')->form());
        $crawler = $this->client->followRedirect();
        self::assertFalse((bool) $this->connection->fetchOne('SELECT is_active FROM admin_users WHERE id = ?', [$other['user']->id()]));

        $this->client->submit($crawler->selectButton('Reaktivieren')->form());
        self::assertResponseRedirects($url);
        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM admin_users WHERE id = ?', [$other['user']->id()]));
    }

    public function test_reset_2fa(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/benutzer/'.$other['user']->id());
        $this->client->submit($crawler->selectButton('2FA zurücksetzen')->form());

        self::assertResponseRedirects();
        self::assertNull($this->connection->fetchOne('SELECT totp_secret_encrypted FROM admin_users WHERE id = ?', [$other['user']->id()]));
    }

    public function test_setting_a_password(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);
        $before = $this->connection->fetchOne('SELECT password_hash FROM admin_users WHERE id = ?', [$other['user']->id()]);

        $crawler = $this->client->request('GET', '/admin/benutzer/'.$other['user']->id());
        $form = $crawler->filter('form[action$="/passwort"]')->form([
            'reset_admin_password[plainPassword][first]' => 'another-long-password',
            'reset_admin_password[plainPassword][second]' => 'another-long-password',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertNotSame($before, $this->connection->fetchOne('SELECT password_hash FROM admin_users WHERE id = ?', [$other['user']->id()]));
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/benutzer/'.$other['user']->id());
        $form = $crawler->filter('form[action$="/passwort"]')->form([
            'reset_admin_password[plainPassword][first]' => 'kurz',
            'reset_admin_password[plainPassword][second]' => 'kurz',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#reset_admin_password_plainPassword_first_error');
    }

    public function test_an_unknown_user_is_a_404(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/benutzer/'.self::UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_posts_without_a_valid_csrf_token_change_nothing(): void
    {
        $this->logInAs();
        $other = $this->createStaff(AdminRole::Editor);
        $id = $other['user']->id();
        // Both headers, as a browser sends them from another site; the client's own Referer would pass.
        $headers = ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/page'];

        $this->client->request('POST', '/admin/benutzer/neu', ['create_admin_user' => [
            'email' => 'evil@example.ch', 'displayName' => 'Evil', 'role' => 'admin',
            'plainPassword' => ['first' => 'a-long-enough-password', 'second' => 'a-long-enough-password'],
            '_token' => 'csrf-token',
        ]], server: $headers);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_users WHERE email = 'evil@example.ch'"));

        $this->client->request('POST', '/admin/benutzer/'.$id, ['change_admin_role' => ['role' => 'admin', '_token' => 'csrf-token']], server: $headers);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('editor', $this->connection->fetchOne('SELECT role FROM admin_users WHERE id = ?', [$id]));

        $this->client->request('POST', '/admin/benutzer/'.$id.'/passwort', ['reset_admin_password' => [
            'plainPassword' => ['first' => 'a-long-enough-password', 'second' => 'a-long-enough-password'],
            '_token' => 'csrf-token',
        ]], server: $headers);
        self::assertResponseStatusCodeSame(422);

        foreach (['deaktivieren' => 'deactivate', 'reaktivieren' => 'reactivate', '2fa-zuruecksetzen' => 'reset_totp'] as $path => $form) {
            $this->client->request('POST', '/admin/benutzer/'.$id.'/'.$path, [$form => ['_token' => 'csrf-token']], server: $headers);
            self::assertResponseRedirects('/admin/benutzer/'.$id, message: $path);
        }

        self::assertTrue((bool) $this->connection->fetchOne('SELECT is_active FROM admin_users WHERE id = ?', [$id]));
        self::assertNotNull($this->connection->fetchOne('SELECT totp_secret_encrypted FROM admin_users WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));
    }
}
