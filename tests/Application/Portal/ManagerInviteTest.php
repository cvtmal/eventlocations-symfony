<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\InviteLocationManager;
use App\Portal\Security\PortalArea;
use App\Portal\Security\PortalLinkSigner;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * Staff add a location manager by an address with no account (owner's request, 2026-11-03): the account, its access,
 * the invitation, the mail's link, the first password and `/konto`, plus the panel's other answers.
 */
final class ManagerInviteTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
    }
    use Factories;

    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const string EMAIL = 'nina.brunner@example.ch';
    private const string PASSWORD = 'ein-langes-passwort';
    private const string VENUE = 'Schloss Seeburg';

    public function test_an_invited_manager_walks_from_the_mail_to_their_location(): void
    {
        $this->forgetPortalThrottling();
        $this->logInAs();
        $venue = VenueFactory::createOne(['name' => self::VENUE]);

        // The help text says what a new address needs before anything is sent.
        $panel = $this->panel($venue);
        self::assertStringContainsString('auch Vor- und Nachnamen angeben', $panel->filter('form[action$="/manager"]')->text());
        $this->client->submit($panel->filter('form[action$="/manager"]')->form([
            'portal_manager[email]' => 'Nina.Brunner@Example.ch',
            'portal_manager[firstName]' => 'Nina',
            'portal_manager[lastName]' => 'Brunner',
        ]));

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', self::EMAIL);
        self::assertStringContainsString(self::VENUE, (string) $mail->getSubject());
        self::assertEmailHtmlBodyContains($mail, 'Guten Tag Nina');
        $link = 1 === preg_match('~href="(http://localhost/konto/passwort-neu/[^"]+)"~', (string) $mail->getHtmlBody(), $match) ? html_entity_decode($match[1]) : '';
        self::assertNotSame('', $link, 'The link is absolute: scheme, host and path from the router.');
        self::assertGreaterThan(time() + PortalLinkSigner::INVITATION_LIFETIME - 60, (int) explode('.', basename($link))[1], 'The mail promises 7 days.');

        $account = $this->connection->fetchAssociative('SELECT id, first_name, last_name, password_hash, is_active FROM portal_users WHERE email = ?', [self::EMAIL]);
        self::assertIsArray($account);
        self::assertSame(['Nina', 'Brunner', null, true], [$account['first_name'], $account['last_name'], $account['password_hash'], $account['is_active']]);
        self::assertSame('active', $this->accessStatus((string) $account['id'], $venue));
        self::assertSame(1, $this->auditCount(InviteLocationManager::class));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts WHERE email = ?', [self::EMAIL]));

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Einladung an '.self::EMAIL.' gesendet.', $crawler->filter('.toast--success')->text());
        self::assertStringContainsString('Einladung offen', $crawler->filter('section[aria-labelledby="portal-managers"] li')->text());

        // The invitee's browser: a session that already exists (here from a failed login) must not survive the login.
        $this->client->getCookieJar()->clear();
        $this->submitManagerLogin(self::EMAIL, 'not-the-password');
        $planted = $this->client->getCookieJar()->get(PortalArea::SESSION_COOKIE, PortalArea::COOKIE_PATH);
        self::assertNotNull($planted);

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Passwort wählen');
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/konto', Response::HTTP_SEE_OTHER);
        $session = $this->client->getCookieJar()->get(PortalArea::SESSION_COOKIE, PortalArea::COOKIE_PATH);
        self::assertNotNull($session);
        self::assertNotSame($planted->getValue(), $session->getValue(), 'The login gives the session a new id.');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::VENUE, $crawler->filter('main')->text());
        self::assertStringContainsString('Ihr Passwort ist gespeichert', $crawler->filter('.toast__message')->text());

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(Response::HTTP_GONE);

        $this->client->getCookieJar()->set($planted);
        $this->client->request('GET', '/konto');
        self::assertResponseRedirects('/konto/anmelden');
    }

    public function test_an_expired_invitation_is_gone_and_points_to_a_new_link(): void
    {
        $user = $this->createManager(confirmed: false);

        $this->client->request('GET', '/konto/passwort-neu/'.$this->links()->resetToken($user, time() - 10));

        self::assertResponseStatusCodeSame(Response::HTTP_GONE);
        self::assertSelectorExists('a[href="/konto/passwort-vergessen"]');
    }

    public function test_an_existing_account_with_a_password_gets_the_short_notice(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne(['name' => self::VENUE]);
        $manager = $this->createManager(attributes: ['firstName' => 'Petra', 'email' => 'petra@example.ch']);

        $this->add($venue, 'petra@example.ch');

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringContainsString(self::VENUE, (string) $mail->getSubject());
        self::assertEmailHtmlBodyContains($mail, 'Guten Tag Petra');
        self::assertEmailHtmlBodyContains($mail, 'http://localhost/konto"');
        self::assertEmailHtmlBodyNotContains($mail, 'passwort-neu');
        self::assertSame('active', $this->accessStatus($manager->id(), $venue));
        self::assertSame(0, $this->auditCount(InviteLocationManager::class));
        self::assertStringContainsString('Manager hinzugefügt. Die E-Mail ist unterwegs.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_an_existing_account_with_no_password_gets_the_invitation(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = $this->createManager(confirmed: false, attributes: ['email' => 'registered@example.ch']);

        $this->add($venue, 'registered@example.ch');

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailHtmlBodyContains($mail, 'http://localhost/konto/passwort-neu/'.$manager->id().'.');
        self::assertSame('active', $this->accessStatus($manager->id(), $venue));
        self::assertSame(1, $this->auditCount(GrantVenueAccess::class));
    }

    public function test_a_deactivated_account_is_refused(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = $this->createManager(active: false, attributes: ['email' => 'off@example.ch']);

        $this->add($venue, 'off@example.ch', 'Otto', 'Off');

        self::assertEmailCount(0);
        self::assertNull($this->accessStatus($manager->id(), $venue));
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertStringContainsString('Dieses Konto ist deaktiviert.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_the_resend_button_mails_the_invitation_again_and_audits_nothing(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $manager = PortalUserFactory::new()->unconfirmed()->create(['firstName' => 'Nina', 'lastName' => 'Brunner', 'email' => self::EMAIL]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        $button = $this->panel($venue)->filter('li form[action$="/manager"] button');
        self::assertSame('Einladung an Nina Brunner erneut senden', $button->attr('aria-label'));
        $this->client->submit($button->form());

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#portal-managers', 303);
        self::assertEmailCount(1);
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertStringContainsString('Einladung erneut gesendet.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_a_manager_with_a_password_has_no_resend_button(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        PortalVenueAccessFactory::new()->active()->create(['user' => PortalUserFactory::createOne(), 'venue' => $venue]);

        $row = $this->panel($venue)->filter('li');

        self::assertCount(0, $row->filter('form[action$="/manager"]'));
        self::assertStringNotContainsString('Einladung offen', $row->text());
    }

    public function test_an_invitation_removed_before_it_was_accepted_still_opens_an_empty_account(): void
    {
        $this->forgetPortalThrottling();
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $this->add($venue, self::EMAIL, 'Nina', 'Brunner');
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        $link = 1 === preg_match('~/konto/passwort-neu/[^"\s]+~', (string) $mail->getHtmlBody(), $match) ? $match[0] : '';
        $userId = (string) $this->connection->fetchOne('SELECT id FROM portal_users WHERE email = ?', [self::EMAIL]);

        $this->client->request('POST', '/admin/pruefung/locations/'.$venue->number().'/manager/'.$userId.'/entfernen', ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
        self::assertNull($this->accessStatus($userId, $venue));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $link);
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/konto', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseRedirects('/konto/locations/neu');
    }

    public function test_a_new_address_needs_both_names(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();

        $this->add($venue, self::EMAIL, 'Nina', '  ');

        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_users'));
        self::assertStringContainsString('bitte Vor- und Nachnamen angeben', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_a_failed_mail_keeps_the_invitation_and_warns_staff(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            if (!$event->isQueued()) {
                throw new \RuntimeException('A mail error.');
            }
        });

        $this->add($venue, self::EMAIL, 'Nina', 'Brunner');

        $userId = (string) $this->connection->fetchOne('SELECT id FROM portal_users WHERE email = ?', [self::EMAIL]);
        self::assertSame('active', $this->accessStatus($userId, $venue));
        self::assertStringContainsString('die E-Mail ging nicht raus', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    private function panel(Venue $venue): Crawler
    {
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        self::assertResponseIsSuccessful();

        return $crawler->filter('aside section[aria-labelledby="portal-managers"]');
    }

    private function add(Venue $venue, string $email, string $firstName = '', string $lastName = ''): void
    {
        $this->client->request('POST', '/admin/pruefung/locations/'.$venue->number().'/manager', ['portal_manager' => [
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            '_token' => self::SAME_SITE_TOKEN,
        ]], server: self::SAME_ORIGIN);
    }

    private function accessStatus(string $userId, Venue $venue): ?string
    {
        $status = $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$userId, $venue->id()]);

        return false === $status ? null : (string) $status;
    }

    private function auditCount(string $command): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [$command]);
    }

    private function links(): PortalLinkSigner
    {
        $links = self::getContainer()->get(PortalLinkSigner::class);
        self::assertInstanceOf(PortalLinkSigner::class, $links);

        return $links;
    }
}
