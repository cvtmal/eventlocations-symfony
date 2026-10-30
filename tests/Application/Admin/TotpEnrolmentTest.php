<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\Command\ResetAdminTotp;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class TotpEnrolmentTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_every_admin_url_leads_to_enrolment(): void
    {
        $this->startEnrolment();

        foreach (['/admin', '/admin/benutzer', '/admin/audit-log'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/admin/2fa/einrichten', message: $url);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedUrls(): iterable
    {
        foreach (['/admin', '/admin/benutzer', '/admin/audit-log'] as $path) {
            foreach (self::encodedSpellings($path) as $spelling => $url) {
                yield $path.', '.$spelling => [$url];
            }
        }
        yield 'trailing slash' => ['/admin/benutzer/'];
        yield 'two trailing slashes' => ['/admin/benutzer//'];
    }

    #[DataProvider('encodedUrls')]
    public function test_an_encoded_admin_url_leads_to_enrolment_too(string $url): void
    {
        $this->startEnrolment();
        $this->sendSessionCookieEverywhere();

        $this->client->request('GET', $url);

        self::assertResponseRedirects('/admin/2fa/einrichten');
    }

    public function test_an_encoded_post_creates_no_user(): void
    {
        $this->startEnrolment();
        $this->sendSessionCookieEverywhere();
        $before = $this->connection->fetchOne('SELECT COUNT(*) FROM admin_users');

        $this->client->request('POST', '/admin%2Fbenutzer/neu', ['create_admin_user' => ['email' => 'mallory@example.ch', 'displayName' => 'Mallory', 'role' => 'admin']]);

        self::assertResponseRedirects('/admin/2fa/einrichten');
        self::assertSame($before, $this->connection->fetchOne('SELECT COUNT(*) FROM admin_users'));
    }

    public function test_the_page_shows_a_qr_code_and_the_key(): void
    {
        $this->startEnrolment();

        $crawler = $this->client->request('GET', '/admin/2fa/einrichten');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.auth__qr[role="img"] svg');
        self::assertStringNotContainsString('<?xml', (string) $this->client->getResponse()->getContent());
        self::assertMatchesRegularExpression('/^[A-Z2-7]{4}( [A-Z2-7]{1,4})+$/', $crawler->filter('.auth__key-value')->text());
    }

    public function test_the_key_stays_the_same_across_reloads(): void
    {
        $this->startEnrolment();

        $first = $this->client->request('GET', '/admin/2fa/einrichten')->filter('.auth__key-value')->text();
        $second = $this->client->request('GET', '/admin/2fa/einrichten')->filter('.auth__key-value')->text();

        self::assertSame($first, $second);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->startEnrolment();

        $this->client->request('GET', '/admin/2fa/einrichten');
        $this->client->submitForm('Einrichten', ['totp_enrolment[code]' => '000000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Der Code ist ungültig oder abgelaufen.');
    }

    public function test_the_right_code_enrols_and_keeps_the_user_logged_in(): void
    {
        $user = $this->startEnrolment();
        $this->enrol();

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast', 'Die Zwei-Faktor-Anmeldung ist eingerichtet.');

        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $encrypted = $this->connection->fetchOne('SELECT totp_secret_encrypted FROM admin_users WHERE id = ?', [$user]);
        self::assertIsString($encrypted);
        self::assertStringStartsWith('v1:', $encrypted);
    }

    public function test_an_enrolled_user_has_nothing_to_set_up(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/2fa/einrichten');

        self::assertResponseRedirects('/admin');
    }

    public function test_a_reset_by_someone_else_ends_the_session(): void
    {
        $user = $this->startEnrolment();
        $this->enrol();
        $this->client->followRedirect();

        $this->dispatch(new ResetAdminTotp($user));

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');
    }

    /**
     * @return string the user's id
     */
    private function startEnrolment(): string
    {
        $staff = $this->createStaff(enrolled: false);
        $this->submitPassword($staff['user']->email());
        self::assertResponseRedirects('/admin');

        return $staff['user']->id();
    }

    private function enrol(): void
    {
        $secret = str_replace(' ', '', $this->client->request('GET', '/admin/2fa/einrichten')->filter('.auth__key-value')->text());
        $this->client->submitForm('Einrichten', ['totp_enrolment[code]' => self::totpCode($secret)]);
    }
}
