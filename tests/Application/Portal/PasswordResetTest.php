<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\Entity\PortalUser;
use App\Portal\Security\PortalLinkSigner;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class PasswordResetTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string EMAIL = 'nora@example.ch';
    private const string NEW_PASSWORD = 'a-brand-new-password';

    public function test_the_login_page_links_here(): void
    {
        $crawler = $this->client->request('GET', '/konto/anmelden');

        self::assertCount(1, $crawler->filter('a.auth__help[href="/konto/passwort-vergessen"]'));
    }

    public function test_a_known_active_user_gets_one_mail_with_a_working_link(): void
    {
        $user = $this->createManager(attributes: ['email' => self::EMAIL]);

        $this->forgot(self::EMAIL);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', self::EMAIL);
        self::assertEmailHtmlBodyContains($mail, 'eine Stunde');
        self::assertEmailTextBodyContains($mail, 'eine Stunde');

        $token = 1 === preg_match('~/konto/passwort-neu/([^"\s]+)~', (string) $mail->getHtmlBody(), $link) ? $link[1] : '';
        self::assertLessThanOrEqual(time() + 3600, (int) explode('.', $token)[1], 'The mail promises one hour.');
        $this->open($token);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($user->id(), $this->links()->resetUserId($token));
    }

    public function test_the_mail_quotes_nothing_the_visitor_typed(): void
    {
        $this->createManager(attributes: ['email' => self::EMAIL, 'firstName' => 'https://evil.example/x', 'lastName' => 'https://evil.example/x']);

        $this->forgot(self::EMAIL);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailHtmlBodyContains($mail, 'Guten Tag');
        self::assertEmailHtmlBodyNotContains($mail, 'evil.example');
        self::assertEmailTextBodyNotContains($mail, 'evil.example');
    }

    public function test_an_account_with_no_password_gets_the_link_too_and_saving_finishes_it(): void
    {
        $user = $this->createManager(confirmed: false, attributes: ['email' => self::EMAIL]);

        $this->forgot(self::EMAIL);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);

        $this->reset(1 === preg_match('~/konto/passwort-neu/([^"\s]+)~', (string) $mail->getHtmlBody(), $link) ? $link[1] : '');
        self::assertResponseRedirects('/konto', 303);
        self::assertNotNull($this->connection->fetchOne('SELECT email_verified_at FROM portal_users WHERE id = ?', [$user->id()]));

        $this->client->getCookieJar()->clear();
        $this->submitManagerLogin(self::EMAIL, self::NEW_PASSWORD);
        self::assertResponseRedirects('/konto');
    }

    public function test_the_address_matches_in_any_case(): void
    {
        $this->createManager(attributes: ['email' => self::EMAIL]);

        $this->forgot(strtoupper(self::EMAIL));

        self::assertEmailCount(1);
    }

    public function test_an_unknown_address_gets_none(): void
    {
        $this->forgot('niemand@example.ch');

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
    }

    public function test_a_deactivated_account_gets_none(): void
    {
        $this->createManager(active: false, attributes: ['email' => self::EMAIL]);

        $this->forgot(self::EMAIL);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
    }

    public function test_the_answers_are_identical(): void
    {
        $this->createManager(attributes: ['email' => 'a@example.ch']);
        $this->createManager(active: false, attributes: ['email' => 'b@example.ch']);
        $this->createManager(confirmed: false, attributes: ['email' => 'd@example.ch']);

        $answers = [];
        foreach (['a@example.ch', 'b@example.ch', 'c@example.ch', 'd@example.ch'] as $email) {
            $this->forgot($email);
            $response = $this->client->getResponse();
            $head = [$response->getStatusCode(), $response->headers->get('Location'), $response->getContent()];
            $answers[] = [...$head, $this->client->followRedirect()->filter('.toast__message')->text()];
        }

        self::assertSame(303, $answers[0][0]);
        self::assertSame('/konto/anmelden', $answers[0][1]);
        self::assertStringContainsString('Postfach', (string) $answers[0][3]);
        self::assertSame($answers[0], $answers[1]);
        self::assertSame($answers[0], $answers[2]);
        self::assertSame($answers[0], $answers[3]);
    }

    public function test_an_invalid_address_is_a_422(): void
    {
        $this->forgot('keine-adresse');

        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_valid_link_sets_the_new_password_and_the_old_one_stops_working(): void
    {
        $user = $this->createManager();

        $this->reset($this->links()->resetToken($user));

        self::assertResponseRedirects('/konto', 303);
        // Saving logged the person in: `/konto` opens, and for an account with no location sends on to the wizard.
        $this->client->followRedirect();
        self::assertResponseRedirects('/konto/locations/neu');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast__message', 'Dein Passwort ist gespeichert');

        $this->client->getCookieJar()->clear();
        $this->submitManagerLogin($user->email());
        self::assertResponseRedirects('/konto/anmelden');

        $this->submitManagerLogin($user->email(), self::NEW_PASSWORD);
        self::assertResponseRedirects('/konto');
    }

    public function test_a_used_link_is_gone_because_the_hash_changed(): void
    {
        $user = $this->createManager();
        $token = $this->links()->resetToken($user);

        $this->reset($token);
        self::assertResponseStatusCodeSame(303);

        $this->open($token);
        self::assertResponseStatusCodeSame(410);
        self::assertSelectorExists('a[href="/konto/passwort-vergessen"]');
    }

    public function test_a_form_left_open_dies_with_its_link(): void
    {
        $user = $this->createManager();
        $token = $this->links()->resetToken($user);
        $this->reset($token);
        $hash = $this->storedHash($user);

        $this->client->request('POST', '/konto/passwort-neu/'.$token, [
            'portal_password' => ['plainPassword' => ['first' => 'another-long-password', 'second' => 'another-long-password']],
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(410);
        self::assertSame($hash, $this->storedHash($user));
    }

    public function test_an_expired_link_is_gone(): void
    {
        $user = $this->createManager();

        $this->open($this->links()->resetToken($user, time() - 10));

        self::assertResponseStatusCodeSame(410);
        self::assertSelectorExists('a[href="/konto/passwort-vergessen"]');
    }

    public function test_an_expired_link_cannot_be_given_a_new_date(): void
    {
        $user = $this->createManager();
        [$id, , $hash] = explode('.', $this->links()->resetToken($user, time() - 10));

        $this->open($id.'.'.(time() + 3600).'.'.$hash);

        self::assertResponseStatusCodeSame(410);
    }

    public function test_a_deactivated_users_link_is_gone(): void
    {
        $user = $this->createManager();
        $token = $this->links()->resetToken($user);
        $em = $this->portalEntityManager();
        $em->find(PortalUser::class, $user->id())?->deactivate();
        $em->flush();

        $this->open($token);

        self::assertResponseStatusCodeSame(410);
    }

    public function test_a_too_short_password_is_a_422_and_changes_nothing(): void
    {
        $user = $this->createManager();
        $hash = $this->storedHash($user);

        $this->reset($this->links()->resetToken($user), 'kurz');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth__form', 'zu kurz');
        self::assertSame($hash, $this->storedHash($user));
    }

    public function test_two_different_passwords_are_a_422_and_change_nothing(): void
    {
        $user = $this->createManager();
        $hash = $this->storedHash($user);
        $token = $this->links()->resetToken($user);

        $this->open($token);
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::NEW_PASSWORD,
            'portal_password[plainPassword][second]' => 'something-else-entirely',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth__form', 'stimmen nicht überein');
        self::assertSame($hash, $this->storedHash($user));
    }

    public function test_the_limiter_is_shared_with_registering(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->client->request('GET', '/konto/registrieren');
            $this->client->submitForm('Konto erstellen', [
                'register[firstName]' => 'Nora',
                'register[lastName]' => 'Keller',
                'register[email]' => 'neu-'.$i.'@example.ch',
            ]);
            self::assertResponseStatusCodeSame(303);
        }

        $this->forgot('c@example.ch');
        self::assertResponseStatusCodeSame(303);
        $this->forgot('c@example.ch');
        self::assertResponseStatusCodeSame(303);

        $this->forgot('c@example.ch');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSelectorTextContains('.form-error', 'Zu viele Versuche');
    }

    public function test_a_throwing_transport_still_gives_the_normal_answer(): void
    {
        $this->createManager(attributes: ['email' => self::EMAIL]);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            if (!$event->isQueued()) {
                throw new \RuntimeException('A mail error.');
            }
        });

        $this->forgot(self::EMAIL);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_running_session_ends_after_a_reset(): void
    {
        $manager = $this->logInAsManager();
        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();

        // The reset happens in another browser; the session of this one must end with the old hash.
        $running = $this->client->getCookieJar()->all();
        $this->client->getCookieJar()->clear();
        $this->reset($this->links()->resetToken($manager));
        self::assertResponseRedirects('/konto', 303);

        $this->client->getCookieJar()->clear();
        foreach ($running as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_a_later_reset_keeps_the_first_confirmation(): void
    {
        $user = $this->createManager();
        $confirmed = $this->connection->fetchOne('SELECT email_verified_at FROM portal_users WHERE id = ?', [$user->id()]);

        $this->reset($this->links()->resetToken($user));
        self::assertResponseStatusCodeSame(303);

        self::assertSame($confirmed, $this->connection->fetchOne('SELECT email_verified_at FROM portal_users WHERE id = ?', [$user->id()]));
    }

    private function forgot(string $email): void
    {
        $this->client->request('GET', '/konto/passwort-vergessen');
        $this->client->submitForm('Link senden', ['portal_email[email]' => $email]);
    }

    private function open(string $token): void
    {
        $this->client->request('GET', '/konto/passwort-neu/'.$token);
    }

    private function reset(string $token, string $password = self::NEW_PASSWORD): void
    {
        $this->open($token);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => $password,
            'portal_password[plainPassword][second]' => $password,
        ]);
    }

    private function storedHash(PortalUser $user): mixed
    {
        return $this->connection->fetchOne('SELECT password_hash FROM portal_users WHERE id = ?', [$user->id()]);
    }

    private function links(): PortalLinkSigner
    {
        $links = self::getContainer()->get(PortalLinkSigner::class);
        self::assertInstanceOf(PortalLinkSigner::class, $links);

        return $links;
    }
}
