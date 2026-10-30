<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class TotpTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_wrong_code_shows_the_error_and_grants_nothing(): void
    {
        $staff = $this->createStaff();
        $this->submitPassword($staff['user']->email());

        $this->submitCode('000000');

        self::assertResponseRedirects('/admin/2fa');
        $this->client->followRedirect();
        self::assertSelectorTextContains('#totp-error', 'Der Code ist ungültig oder abgelaufen.');
        self::assertSelectorExists('#totp-code[aria-invalid="true"][aria-describedby="totp-error"]');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa');
    }

    public function test_a_replayed_code_is_rejected(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);
        $code = self::totpCode($staff['secret']);

        $this->submitPassword($staff['user']->email());
        $this->submitCode($code);
        self::assertResponseRedirects('/admin');

        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->filter('form.sidebar__logout-form')->form());

        $this->submitPassword($staff['user']->email());
        $this->submitCode($code);

        self::assertResponseRedirects('/admin/2fa');
    }

    public function test_five_wrong_codes_end_the_login(): void
    {
        $staff = $this->createStaff();
        $this->submitPassword($staff['user']->email());

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->submitCode('000000');
            self::assertResponseRedirects('/admin/2fa');
        }
        $this->submitCode('000000');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Zu viele Versuche.');
        $this->client->request('GET', '/admin/2fa');
        self::assertResponseRedirects('/admin/login');
    }

    public function test_even_the_right_code_is_refused_until_the_window_ends(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);
        $this->submitPassword($staff['user']->email());
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitCode('000000');
        }

        // A new address: the password limiter does not stop this login, the per-user code limiter must.
        $this->client->setServerParameter('REMOTE_ADDR', '10.20.30.41');
        $this->submitPassword($staff['user']->email());
        $this->submitCode(self::totpCode($staff['secret']));

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Zu viele Versuche.');
    }

    public function test_cancelling_the_code_step_logs_out(): void
    {
        $staff = $this->createStaff();
        $this->submitPassword($staff['user']->email());
        $this->client->request('GET', '/admin/2fa');

        $this->client->submitForm('Abbrechen');

        self::assertResponseRedirects('/admin/login');
        $this->client->request('GET', '/admin/2fa');
        self::assertResponseRedirects('/admin/login');
    }
}
