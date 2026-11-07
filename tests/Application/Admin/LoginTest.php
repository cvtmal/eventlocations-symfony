<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LoginTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_the_login_page_links_the_favicon_but_not_the_web_manifest(): void
    {
        $this->client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="icon"][href="/brand/favicon.svg"]');
        self::assertSelectorNotExists('link[rel="manifest"]');
    }

    public function test_a_valid_password_leads_to_the_code_form(): void
    {
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email());

        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/2fa');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bestätigungscode');
    }

    public function test_the_dashboard_stays_closed_before_the_code(): void
    {
        $staff = $this->createStaff();
        $this->submitPassword($staff['user']->email());

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/2fa');
    }

    public function test_a_valid_code_leads_to_the_dashboard_and_records_the_login(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);

        $this->logInFully($staff['user'], $staff['secret']);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Guten Tag, Andrea');
        self::assertNotFalse($this->connection->fetchOne('SELECT last_login_at FROM admin_users WHERE id = ? AND last_login_at IS NOT NULL', [$staff['user']->id()]));
    }

    public function test_the_password_step_alone_records_no_login(): void
    {
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email());

        self::assertFalse($this->connection->fetchOne('SELECT id FROM admin_users WHERE id = ? AND last_login_at IS NOT NULL', [$staff['user']->id()]));
    }

    public function test_a_wrong_password_shows_the_error(): void
    {
        $staff = $this->createStaff();

        $this->submitPassword($staff['user']->email(), 'wrong-password-here');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort ist falsch.');
        self::assertSelectorExists('#login-password[aria-invalid="true"][aria-describedby="login-error"]');
    }

    public function test_an_unknown_email_shows_the_same_error(): void
    {
        $this->submitPassword('nobody@example.ch');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort ist falsch.');
    }

    public function test_an_inactive_user_with_the_right_password_is_told_so(): void
    {
        $staff = $this->createStaff();
        $staff['user']->deactivate();
        $this->entityManager()->flush();

        $this->submitPassword($staff['user']->email());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Dieses Konto ist deaktiviert.');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function test_a_wrong_password_does_not_disclose_an_inactive_account(): void
    {
        $staff = $this->createStaff();
        $staff['user']->deactivate();
        $this->entityManager()->flush();

        $this->submitPassword($staff['user']->email(), 'wrong-password-here');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort ist falsch.');
        self::assertAnySelectorTextNotContains('.form-error', 'deaktiviert');
    }

    public function test_the_sixth_attempt_is_throttled(): void
    {
        $staff = $this->createStaff(AdminRole::Editor);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitPassword($staff['user']->email(), 'wrong-password-here');
        }
        $this->submitPassword($staff['user']->email());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Zu viele Versuche.');
    }

    public function test_logout_by_post_with_csrf_ends_the_session(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);
        $this->logInFully($staff['user'], $staff['secret']);
        $this->client->followRedirect();

        $this->client->submit($this->client->getCrawler()->filter('form.sidebar__logout-form')->form());

        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    public function test_logout_by_get_does_not_log_out(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/logout');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function test_logout_by_get_does_not_log_out_even_with_the_token(): void
    {
        $this->logInAs();
        $token = $this->client->request('GET', '/admin')->filter('form.sidebar__logout-form input[name="_csrf_token"]')->attr('value');

        // The test client sends the page above as a same-origin Referer.
        $this->client->request('GET', '/admin/logout?_csrf_token='.$token);
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    public function test_a_logged_in_user_skips_the_login_page(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/login');

        self::assertResponseRedirects('/admin');
    }
}
