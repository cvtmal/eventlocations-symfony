<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Crm\ForgedPosts;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class ReviewQueueTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
    }
    use Factories;
    use ForgedPosts;

    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const string UNKNOWN_ACCOUNT = '00000000-0000-7000-8000-000000000001';

    public function test_the_queue_shows_name_email_phone_and_location(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne(['name' => 'Schloss Seeblick']);
        $manager = PortalUserFactory::createOne(['firstName' => 'Petra', 'lastName' => 'Keller', 'email' => 'petra@example.ch']);
        $this->connection->executeStatement('UPDATE portal_users SET phone = ? WHERE id = ?', ['+41 44 123 45 67', $manager->id()]);
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        $crawler = $this->client->request('GET', '/admin/pruefung');

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-requests tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Petra Keller', $row->text());
        self::assertStringContainsString('petra@example.ch', $row->text());
        self::assertStringContainsString('+41 44 123 45 67', $row->text());
        self::assertSame('Schloss Seeblick', $row->filter('a[href="/admin/locations/'.$venue->number().'"]')->text());
    }

    public function test_an_empty_phone_shows_the_normal_empty_display(): void
    {
        $this->logInAs();
        $this->pendingRequest();

        $crawler = $this->client->request('GET', '/admin/pruefung');

        self::assertSame('—', $crawler->filter('#review-requests tbody td')->eq(2)->text());
    }

    public function test_a_deactivated_account_stays_in_the_queue_marked_and_without_annehmen(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::new()->deactivated()->create(['email' => 'deactivated@example.ch']);
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => VenueFactory::createOne()]);
        $this->pendingRequest();

        $crawler = $this->client->request('GET', '/admin/pruefung');

        $rows = $crawler->filter('#review-requests tbody tr');
        $deactivated = $rows->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'deactivated@example.ch'));
        $active = $rows->reduce(static fn (Crawler $row): bool => !str_contains($row->text(), 'deactivated@example.ch'));
        self::assertCount(1, $deactivated);
        self::assertSame('Konto deaktiviert', $deactivated->filter('.pill')->text());
        self::assertCount(0, $deactivated->filter('button:not([formaction])'), 'no "Annehmen" the controller would refuse');
        self::assertSame('Ablehnen', $deactivated->filter('button[formaction]')->text());
        self::assertCount(1, $active);
        self::assertCount(0, $active->filter('.pill'));
        self::assertSame('Annehmen', $active->filter('button:not([formaction])')->text());
    }

    public function test_approving_a_deactivated_account_is_refused_and_mails_nobody(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::new()->deactivated()->create();
        $venue = VenueFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        $this->decide($manager, $venue, 'annehmen');

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertEmailCount(0);
        self::assertSame('pending', $this->accessStatus($manager, $venue));
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertStringContainsString('Dieses Konto ist deaktiviert.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_the_tab_counts_the_requests(): void
    {
        $this->logInAs();
        $this->pendingRequest();
        $this->pendingRequest();
        PortalVenueAccessFactory::new()->active()->create(['user' => PortalUserFactory::createOne(), 'venue' => VenueFactory::createOne()]);

        $crawler = $this->client->request('GET', '/admin/pruefung');

        self::assertCount(2, $crawler->filter('#review-requests tbody tr'));
        $tab = $crawler->filter('.tabs .tab--active');
        self::assertStringContainsString('Zugriffsanfragen', $tab->text());
        self::assertSame('2', trim($tab->filter('.pill')->text()));
    }

    public function test_an_empty_queue_says_so(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', '/admin/pruefung');

        self::assertSame('Keine offenen Zugriffsanfragen', $crawler->filter('.empty__title')->text());
    }

    public function test_an_editor_may_approve(): void
    {
        $this->logInAs(AdminRole::Editor);
        [$manager, $venue] = $this->pendingRequest();

        $crawler = $this->client->request('GET', '/admin/pruefung');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/annehmen"]')->form());

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertSame('active', $this->accessStatus($manager, $venue));
    }

    public function test_approve_sets_active_mails_and_audits(): void
    {
        $staff = $this->logInAs();
        [$manager, $venue] = $this->pendingRequest(venueName: 'Schloss Seeblick');

        $crawler = $this->client->request('GET', '/admin/pruefung');
        $this->client->submit($crawler->filter('form[action$="/annehmen"]')->form());

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertSame('active', $this->accessStatus($manager, $venue));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', $manager->email());
        self::assertEmailHeaderSame($mail, 'Subject', 'Ihr Zugriff auf Schloss Seeblick ist bestätigt');

        $audit = $this->connection->fetchAllAssociative('SELECT actor_type, actor_id, subject_type, subject_id FROM admin_audit_log WHERE command = ?', [GrantVenueAccess::class]);
        self::assertSame([['actor_type' => 'admin_user', 'actor_id' => $staff['user']->id(), 'subject_type' => 'portal_user', 'subject_id' => $manager->id()]], $audit);

        self::assertStringContainsString('Zugriff bestätigt.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_approve_creates_the_crm_contact(): void
    {
        $this->logInAs();
        [$manager, $venue] = $this->pendingRequest();

        $this->decide($manager, $venue, 'annehmen');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts WHERE email = ?', [$manager->email()]));
    }

    public function test_a_second_approve_writes_no_second_audit_row(): void
    {
        $this->logInAs();
        [$manager, $venue] = $this->pendingRequest();

        $this->decide($manager, $venue, 'annehmen');
        $this->decide($manager, $venue, 'annehmen');

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertEmailCount(0);
        self::assertSame(1, $this->auditCount(GrantVenueAccess::class));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertStringContainsString('Diese Anfrage ist nicht mehr offen.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_bad_token_changes_nothing(array $token, array $server): void
    {
        $this->logInAs();
        [$manager, $venue] = $this->pendingRequest();

        foreach (['annehmen', 'ablehnen'] as $decision) {
            $this->client->request('POST', self::path($manager->id(), $venue, $decision), $token, server: $server);

            self::assertResponseRedirects('/admin/pruefung', 303);
            self::assertEmailCount(0);
            self::assertSame('pending', $this->accessStatus($manager, $venue));
            self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast--error')->text());
        }
        self::assertSame(0, $this->auditCount(GrantVenueAccess::class));
        self::assertSame(0, $this->auditCount(RevokeVenueAccess::class));
    }

    public function test_a_request_that_is_gone_flashes_and_redirects(): void
    {
        $this->logInAs();
        $venue = VenueFactory::createOne();

        foreach (['annehmen', 'ablehnen'] as $decision) {
            $this->client->request('POST', self::path(self::UNKNOWN_ACCOUNT, $venue, $decision), ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);

            self::assertResponseRedirects('/admin/pruefung', 303);
            self::assertEmailCount(0);
            self::assertStringContainsString('Diese Anfrage ist nicht mehr offen.', $this->client->followRedirect()->filter('.toast--error')->text());
        }
    }

    public function test_reject_deletes_the_row_mails_and_audits(): void
    {
        $staff = $this->logInAs();
        [$manager, $venue] = $this->pendingRequest(venueName: 'Schloss Seeblick');

        $crawler = $this->client->request('GET', '/admin/pruefung');
        $this->client->submit($crawler->filter('button[formaction$="/ablehnen"]')->form());

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertNull($this->accessStatus($manager, $venue));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', $manager->email());
        self::assertEmailHeaderSame($mail, 'Subject', 'Ihre Anfrage für Schloss Seeblick');

        $audit = $this->connection->fetchAllAssociative('SELECT actor_type, actor_id, subject_type, subject_id FROM admin_audit_log WHERE command = ?', [RevokeVenueAccess::class]);
        self::assertSame([['actor_type' => 'admin_user', 'actor_id' => $staff['user']->id(), 'subject_type' => 'portal_user', 'subject_id' => $manager->id()]], $audit);

        self::assertStringContainsString('Anfrage abgelehnt.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    public function test_after_reject_the_manager_may_ask_again(): void
    {
        $venue = VenueFactory::createOne(['name' => 'Schloss Seeblick']);
        $manager = $this->logInAsManager();
        $this->askThroughTheSearch();
        self::assertSame('pending', $this->accessStatus($manager, $venue));

        $this->logInAs();
        $this->decide($manager, $venue, 'ablehnen');
        self::assertNull($this->accessStatus($manager, $venue));

        $this->askThroughTheSearch();
        self::assertSame('pending', $this->accessStatus($manager, $venue));
    }

    public function test_a_failed_mail_keeps_the_approve_and_warns_staff(): void
    {
        $this->logInAs();
        $manager = $this->createManager(attributes: ['email' => 'not-an-address']);
        $venue = VenueFactory::createOne();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        $this->decide($manager, $venue, 'annehmen');

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertSame('active', $this->accessStatus($manager, $venue));
        self::assertStringContainsString('die E-Mail ging nicht raus', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    /**
     * `CHECK (false) NOT VALID` refuses every new row and leaves the old ones alone, so the flush of
     * `LinkLocationManager` really fails. DDL is transactional: tearDown rolls this back.
     */
    public function test_a_failing_crm_does_not_fail_the_approve(): void
    {
        $this->connection->executeStatement('ALTER TABLE crm_activities ADD CONSTRAINT test_refuses_all CHECK (false) NOT VALID');
        $this->logInAs();
        [$manager, $venue] = $this->pendingRequest();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->decide($manager, $venue, 'annehmen');

        self::assertResponseRedirects('/admin/pruefung', 303);
        self::assertSame('active', $this->accessStatus($manager, $venue));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_contacts'));
        self::assertStringContainsString('Zugriff bestätigt.', $this->client->followRedirect()->filter('.toast--success')->text());
    }

    /**
     * @return array{PortalUser, Venue}
     */
    private function pendingRequest(string $venueName = 'Berghof'): array
    {
        $manager = PortalUserFactory::createOne();
        $venue = VenueFactory::createOne(['name' => $venueName]);
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => $venue]);

        return [$manager, $venue];
    }

    private function decide(PortalUser $manager, Venue $venue, string $decision): void
    {
        $this->client->request('POST', self::path($manager->id(), $venue, $decision), ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
    }

    private static function path(string $userId, Venue $venue, string $decision): string
    {
        return '/admin/pruefung/anfragen/'.$userId.'/'.$venue->number().'/'.$decision;
    }

    private function askThroughTheSearch(): void
    {
        $crawler = $this->client->request('GET', '/konto/locations/suchen?q=seeblick');
        $this->client->submit($crawler->filter('form[action="/konto/locations/zugriff"]')->form());
        self::assertResponseRedirects('/konto/locations/suchen?q=seeblick', 303);
    }

    private function accessStatus(PortalUser $manager, Venue $venue): ?string
    {
        $status = $this->connection->fetchOne('SELECT status FROM portal_venue_access WHERE portal_user_id = ? AND venue_id = ?', [$manager->id(), $venue->id()]);

        return false === $status ? null : (string) $status;
    }

    private function auditCount(string $command): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [$command]);
    }
}
