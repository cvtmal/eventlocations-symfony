<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\Entity\PortalUser;
use App\Portal\Repository\PortalUserRepository;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class RegisterTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string EMAIL = 'neu@example.ch';

    private const string SPAM = 'Gewinn! Jetzt ansehen: https://evil.example/x';
    private const string PASSWORD = 'long-enough-password';

    public function test_the_form_opens_without_a_session(): void
    {
        $this->client->request('GET', '/konto/registrieren');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Konto erstellen');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function test_the_form_asks_for_no_password(): void
    {
        $crawler = $this->client->request('GET', '/konto/registrieren');

        self::assertCount(0, $crawler->filter('input[type="password"]'));
    }

    public function test_the_honeypot_is_hidden_and_out_of_the_tab_order(): void
    {
        $crawler = $this->client->request('GET', '/konto/registrieren');

        self::assertSame('-1', $crawler->filter('.visually-hidden input[name="register[website]"]')->attr('tabindex'));
    }

    public function test_a_new_address_gets_an_account_with_no_password_and_one_mail(): void
    {
        $this->register(['register[email]' => 'Neu@Example.ch']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', self::EMAIL);
        self::assertSame('Wähle dein Passwort', $mail->getSubject());
        self::assertEmailHtmlBodyContains($mail, 'Hallo');
        self::assertEmailHtmlBodyContains($mail, '48 Stunden');
        self::assertEmailHtmlBodyContains($mail, 'http://localhost/konto/passwort-neu/');
        self::assertEmailTextBodyContains($mail, '/konto/passwort-neu/');

        $user = $this->findUser(self::EMAIL);
        self::assertNotNull($user);
        self::assertFalse($user->isConfirmed());
        self::assertTrue($user->isActive());
        self::assertSame('Nora', $user->firstName());
        self::assertSame('Keller', $user->lastName());
        self::assertNull($user->passwordHash());
        self::assertSame(1, $this->countUsers());
    }

    public function test_the_mail_quotes_nothing_the_visitor_typed(): void
    {
        $this->register(['register[firstName]' => self::SPAM, 'register[lastName]' => self::SPAM]);

        self::assertResponseStatusCodeSame(303);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailHtmlBodyContains($mail, 'Hallo');
        self::assertEmailHtmlBodyNotContains($mail, 'evil.example');
        self::assertEmailTextBodyNotContains($mail, 'evil.example');
    }

    public function test_the_mails_link_sets_the_password_confirms_and_then_dies(): void
    {
        $this->register();
        $link = $this->linkInTheMail();
        self::assertGreaterThan(time() + 47 * 3600, (int) explode('.', basename($link))[1], 'The mail promises 48 hours.');

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Passwort wählen');
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);
        // Saving logs the person in: the profile opens without the login form.
        self::assertResponseRedirects('/konto', 303);

        $user = $this->findUser(self::EMAIL);
        self::assertNotNull($user);
        self::assertTrue($user->isConfirmed());
        self::assertNotNull($user->passwordHash());

        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
    }

    public function test_opening_the_mails_link_alone_changes_nothing(): void
    {
        $this->register();

        $this->client->request('GET', $this->linkInTheMail());

        self::assertResponseIsSuccessful();
        $user = $this->findUser(self::EMAIL);
        self::assertNotNull($user);
        self::assertNull($user->passwordHash());
        self::assertFalse($user->isConfirmed());
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function test_a_known_address_with_no_password_gets_one_mail_and_nothing_new(): void
    {
        $this->createManager(confirmed: false, attributes: ['email' => self::EMAIL]);

        $this->register(['register[firstName]' => 'Fremd']);

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(1);
        self::assertSame(1, $this->countUsers());
        $user = $this->findUser(self::EMAIL);
        self::assertNotNull($user);
        self::assertNull($user->passwordHash());
        self::assertNotSame('Fremd', $user->firstName());
    }

    public function test_a_known_address_with_a_password_gets_no_mail(): void
    {
        $this->createManager(attributes: ['email' => self::EMAIL]);

        $this->register();

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
        self::assertSame(1, $this->countUsers());
    }

    public function test_a_deactivated_address_gets_no_mail(): void
    {
        $this->createManager(confirmed: false, active: false, attributes: ['email' => self::EMAIL]);

        $this->register();

        self::assertResponseStatusCodeSame(303);
        self::assertEmailCount(0);
    }

    public function test_every_case_answers_the_same(): void
    {
        $answers = [];

        $this->register(['register[email]' => 'neu-a@example.ch']);
        $answers[] = $this->answer();

        $this->createManager(confirmed: false, attributes: ['email' => 'neu-b@example.ch']);
        $this->register(['register[email]' => 'neu-b@example.ch']);
        $answers[] = $this->answer();

        $this->createManager(attributes: ['email' => 'neu-c@example.ch']);
        $this->register(['register[email]' => 'neu-c@example.ch']);
        $answers[] = $this->answer();

        $this->createManager(confirmed: false, active: false, attributes: ['email' => 'neu-d@example.ch']);
        $this->register(['register[email]' => 'neu-d@example.ch']);
        $answers[] = $this->answer();

        self::assertSame(303, $answers[0]['status']);
        self::assertSame('/konto/anmelden', $answers[0]['target']);
        self::assertStringContainsString('Postfach', $answers[0]['flash']);
        self::assertSame($answers[0], $answers[1]);
        self::assertSame($answers[0], $answers[2]);
        self::assertSame($answers[0], $answers[3]);
    }

    public function test_an_invalid_form_is_a_422_and_stores_nothing(): void
    {
        $this->register(['register[email]' => 'keine-adresse']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[aria-invalid="true"]');
        self::assertSame(0, $this->countUsers());
        self::assertEmailCount(0);
    }

    public function test_a_filled_honeypot_gets_the_normal_answer_and_nothing_happens(): void
    {
        $this->register(['register[website]' => 'https://spam.example']);

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');
        self::assertSame(0, $this->countUsers());
        self::assertEmailCount(0);
    }

    public function test_a_filled_honeypot_costs_nothing_from_the_limiter(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->register(['register[website]' => 'https://spam.example']);
        }

        self::assertResponseStatusCodeSame(303);
    }

    public function test_the_sixth_post_in_an_hour_is_refused(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->register(['register[email]' => 'neu-'.$i.'@example.ch']);
            self::assertResponseStatusCodeSame(303);
        }

        $this->register(['register[email]' => 'neu-6@example.ch']);

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertSelectorTextContains('.form-error', 'Zu viele Versuche');
        self::assertSame(5, $this->countUsers());
        self::assertEmailCount(0);
    }

    public function test_typos_cost_nothing_from_the_limiter(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->register(['register[email]' => 'keine-adresse']);
            self::assertResponseStatusCodeSame(422);
        }

        $this->register();

        self::assertResponseStatusCodeSame(303);
    }

    public function test_a_throwing_mail_transport_still_gives_the_normal_answer(): void
    {
        $this->failEveryMail();

        $this->register();

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');
        self::assertNotNull($this->findUser(self::EMAIL));
    }

    public function test_a_failed_mail_logs_no_token_and_no_address(): void
    {
        $this->failEveryMail();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->enableDebug();

        $this->register();

        self::assertResponseStatusCodeSame(303);
        $log = print_r($logger->getLogs(), true);
        self::assertStringContainsString('mail failed', $log);
        self::assertStringNotContainsString(self::EMAIL, $log);
        self::assertStringNotContainsString('konto/passwort-neu', $log);
    }

    /**
     * The stub lets the request past the lookup, so the unique index stops it, as in a real race.
     */
    public function test_a_lost_race_gets_the_normal_answer(): void
    {
        $this->createManager(attributes: ['email' => self::EMAIL]);
        $users = $this->createStub(PortalUserRepository::class);
        $users->method('findOneByEmail')->willReturn(null);
        self::getContainer()->set(PortalUserRepository::class, $users);

        $this->register();

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects('/konto/anmelden');
        self::assertEmailCount(0);
        self::assertSame(1, $this->countUsers());
    }

    public function test_a_nul_in_a_text_field_is_a_422(): void
    {
        $crawler = $this->client->request('GET', '/konto/registrieren');
        $values = $crawler->selectButton('Konto erstellen')->form()->getPhpValues();
        \assert(\is_array($values['register']));
        $values['register'] = ['firstName' => "No\0ra", 'lastName' => 'Keller', 'email' => self::EMAIL] + $values['register'];

        $this->client->request('POST', '/konto/registrieren', $values, server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countUsers());
    }

    /**
     * @param array<string, string> $fields
     */
    private function register(array $fields = []): void
    {
        $this->client->request('GET', '/konto/registrieren');
        $this->client->submitForm('Konto erstellen', $fields + [
            'register[firstName]' => 'Nora',
            'register[lastName]' => 'Keller',
            'register[email]' => self::EMAIL,
        ]);
    }

    /**
     * @return array{status: int, target: ?string, body: string|false, flash: string}
     */
    private function answer(): array
    {
        $response = $this->client->getResponse();
        $status = $response->getStatusCode();
        $target = $response->headers->get('Location');
        $body = $response->getContent();

        return ['status' => $status, 'target' => $target, 'body' => $body, 'flash' => $this->client->followRedirect()->filter('.toast__message')->text()];
    }

    private function linkInTheMail(): string
    {
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);

        return 1 === preg_match('~/konto/passwort-neu/[^"\s]+~', (string) $mail->getHtmlBody(), $link) ? $link[0] : '';
    }

    private function findUser(string $email): ?PortalUser
    {
        $this->portalEntityManager()->clear();
        $users = self::getContainer()->get(PortalUserRepository::class);
        self::assertInstanceOf(PortalUserRepository::class, $users);

        return $users->findOneByEmail($email);
    }

    private function countUsers(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM portal_users');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * On the transport's event, so it comes back as `HandlerFailedException`, as an SMTP error does.
     */
    private function failEveryMail(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            if (!$event->isQueued()) {
                throw new \RuntimeException('A mail error.');
            }
        });
    }
}
