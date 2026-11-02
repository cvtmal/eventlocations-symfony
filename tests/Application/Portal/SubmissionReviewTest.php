<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Command\AcceptSubmission;
use App\Portal\Command\RejectSubmission;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Crm\ForgedPosts;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tab "Neue Locations" (#45, POR-13): staff accept or send back what a manager submitted.
 */
final class SubmissionReviewTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
    }
    use Factories;
    use ForgedPosts;

    private const string QUEUE = '/admin/pruefung?tab=neu';
    private const string BADGE = '.sidebar__item[href="/admin/pruefung"] .sidebar__badge';
    private const string SAME_SITE_TOKEN = 'csrf-token';
    private const array SAME_ORIGIN = ['HTTP_SEC_FETCH_SITE' => 'same-origin'];
    private const string REASON = 'Die Fotos zeigen ein anderes Haus.';

    public function test_the_tab_lists_a_submission_with_its_preview_link(): void
    {
        $this->logInAs();
        $venue = $this->waiting('Seehaus', $this->manager('Petra', 'Keller', 'petra@example.ch'));
        $this->connection->executeStatement('UPDATE venue_translations SET region = ? WHERE venue_id = ?', ['Zürichsee', $venue->id()]);

        $crawler = $this->client->request('GET', self::QUEUE);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-submissions tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('Seehaus', $row->text());
        self::assertStringContainsString('Zürichsee', $row->text());
        self::assertStringContainsString('Petra Keller', $row->text());
        self::assertSame('Vorschau', $row->filter('a[href="/admin/locations/'.$venue->number().'/vorschau"]')->text());
        $tab = $crawler->filter('.tabs .tab--active');
        self::assertStringContainsString('Neue Locations', $tab->text());
        self::assertSame('1', trim($tab->filter('.pill')->text()));
    }

    public function test_an_empty_tab_says_so(): void
    {
        $this->logInAs();

        $crawler = $this->client->request('GET', self::QUEUE);

        self::assertSame('Keine neuen Locations.', $crawler->filter('.empty__title')->text());
    }

    public function test_the_badge_counts_submissions_too(): void
    {
        $this->logInAs();
        PortalVenueAccessFactory::createOne(['venue' => $this->unpublished('Berghof')]);
        $this->waiting();

        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame(self::BADGE, '2');
    }

    public function test_the_widget_total_stays_access_requests_only(): void
    {
        $this->logInAs();
        foreach (range(1, 6) as $request) {
            PortalVenueAccessFactory::createOne(['venue' => $this->unpublished('Berghof '.$request)]);
        }
        $this->waiting();

        $crawler = $this->client->request('GET', '/admin');

        self::assertSelectorTextSame(self::BADGE, '7');
        $widget = $crawler->filter('.widget')->reduce(static fn (Crawler $widget): bool => 'Zugriffsanfragen' === $widget->filter('h2')->text());
        self::assertSame('Alle 6 ansehen', $widget->filter('.widget__head-link a')->text());
    }

    public function test_accept_publishes_mails_and_leaves_the_queue(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = $this->waiting('Seehaus', $this->manager('Petra', 'Keller', 'petra@example.ch'));

        $crawler = $this->client->request('GET', self::QUEUE);
        $this->client->submit($crawler->filter('form[action$="/annehmen"]')->form());

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertSame('published', $this->venueStatus($venue));
        self::assertSame('accepted', $this->submissionStatus($venue));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', 'petra@example.ch');
        self::assertEmailHeaderSame($mail, 'Subject', 'Seehaus ist online');

        $audit = $this->connection->fetchAllAssociative('SELECT actor_id, subject_type, subject_id FROM admin_audit_log WHERE command = ?', [AcceptSubmission::class]);
        self::assertSame([['actor_id' => $staff['user']->id(), 'subject_type' => 'portal_submission', 'subject_id' => (string) $venue->number()]], $audit);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Location angenommen.', $crawler->filter('.toast--success')->text());
        self::assertCount(0, $crawler->filter('#review-submissions form'));
    }

    public function test_accept_with_something_missing_names_it_and_stays_in_the_queue(): void
    {
        $this->logInAs();
        $venue = $this->waiting('Seehaus', photo: false);

        $this->decide($venue);

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertEmailCount(0);
        self::assertSame('unpublished', $this->venueStatus($venue));
        self::assertSame('submitted', $this->submissionStatus($venue));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('noch nicht vollständig: Keine Fotos', $crawler->filter('.toast--error')->text());
        self::assertCount(1, $crawler->filter('#review-submissions form'));
    }

    public function test_accept_without_a_manager_mails_nobody(): void
    {
        $this->logInAs();
        $venue = $this->waiting();
        $this->connection->executeStatement('UPDATE portal_submissions SET submitted_by = NULL WHERE venue_id = ?', [$venue->id()]);

        $this->decide($venue);

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertEmailCount(0);
        self::assertSame('published', $this->venueStatus($venue));
    }

    public function test_reject_needs_a_reason(): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $crawler = $this->client->request('GET', $this->url($venue, 'ablehnen'));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="reject_submission"]')->form(), ['reject_submission[reason]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Bitte geben Sie einen Grund an.');
        self::assertSame('submitted', $this->submissionStatus($venue));
        self::assertEmailCount(0);
    }

    public function test_a_reason_over_the_limit_is_refused(): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $crawler = $this->client->request('GET', $this->url($venue, 'ablehnen'));
        $this->client->submit($crawler->filter('form[name="reject_submission"]')->form(), ['reject_submission[reason]' => str_repeat('a', 1001)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Bitte höchstens 1000 Zeichen eingeben.');
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_reject_mails_the_reason_and_returns_to_the_check(): void
    {
        $this->logInAs(AdminRole::Editor);
        $manager = $this->createManager(attributes: ['email' => 'petra@example.ch']);
        $venue = $this->waiting('Seehaus', $manager);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        $crawler = $this->client->request('GET', self::QUEUE);
        $this->client->click($crawler->filter('#review-submissions a[href$="/ablehnen"]')->link());
        $this->reject();

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertSame('rejected', $this->submissionStatus($venue));
        self::assertSame('unpublished', $this->venueStatus($venue));

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', 'petra@example.ch');
        self::assertEmailHeaderSame($mail, 'Subject', 'Ihre Location Seehaus');
        self::assertEmailHtmlBodyContains($mail, self::REASON);
        self::assertEmailHtmlBodyContains($mail, '/konto/locations/'.$venue->number().'/einrichten/pruefen');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [RejectSubmission::class]));
        self::assertStringContainsString('Location abgelehnt.', $this->client->followRedirect()->filter('.toast--success')->text());

        $this->logInAsManager($manager);
        $this->client->request('GET', '/konto/locations/'.$venue->number().'/einrichten/pruefen');
        self::assertSelectorTextContains('main', self::REASON);
    }

    public function test_reject_and_resubmit_lands_in_the_queue_again(): void
    {
        $manager = $this->createManager();
        $venue = $this->waiting('Seehaus', $manager);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        $this->logInAs();
        $this->client->request('GET', $this->url($venue, 'ablehnen'));
        $this->reject();
        self::assertSame('rejected', $this->submissionStatus($venue));

        $this->logInAsManager($manager);
        $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/einrichten/pruefen');
        $this->client->submit($crawler->filter('form[name="submit_location"]')->form(), ['submit_location[rights]' => '1']);

        self::assertSame('submitted', $this->submissionStatus($venue));
        $crawler = $this->client->request('GET', self::QUEUE);
        self::assertCount(1, $crawler->filter('#review-submissions form'));
    }

    public function test_a_second_accept_says_gone(): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $this->decide($venue);
        $this->decide($venue);

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertEmailCount(0);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [AcceptSubmission::class]));
        self::assertStringContainsString('Diese Anfrage ist nicht mehr offen.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_a_row_that_is_gone_redirects_to_the_queue(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin/pruefung/neu/999999999/ablehnen');

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertStringContainsString('Diese Anfrage ist nicht mehr offen.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_failed_token_changes_nothing(array $token, array $server): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $this->client->request('POST', $this->url($venue, 'annehmen'), $token, server: $server);

        self::assertResponseRedirects(self::QUEUE, 303);
        self::assertEmailCount(0);
        self::assertSame('submitted', $this->submissionStatus($venue));
        self::assertSame('unpublished', $this->venueStatus($venue));
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_a_failing_mail_flashes_the_warning(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::createOne(['email' => 'not-an-address']);

        $accepted = $this->waiting('Seehaus', $manager);
        $this->decide($accepted);

        self::assertSame('published', $this->venueStatus($accepted));
        self::assertStringContainsString('die E-Mail ging nicht raus', $this->client->followRedirect()->filter('.toast--error')->text());

        $rejected = $this->waiting('Berghof', $manager);
        $this->client->request('GET', $this->url($rejected, 'ablehnen'));
        $this->reject();

        self::assertSame('rejected', $this->submissionStatus($rejected));
        self::assertStringContainsString('die E-Mail ging nicht raus', $this->client->followRedirect()->filter('.toast--error')->text());
    }

    public function test_a_cms_publish_empties_the_queue(): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        $this->client->submit($crawler->filter('form[name="publish"]')->form());

        self::assertSame('published', $this->venueStatus($venue));
        self::assertSame('submitted', $this->submissionStatus($venue));
        $crawler = $this->client->request('GET', self::QUEUE);
        self::assertCount(0, $crawler->filter('#review-submissions form'));
        self::assertSelectorNotExists(self::BADGE);
    }

    public function test_delete_removes_the_location_and_both_portal_rows(): void
    {
        $this->logInAs();
        $manager = PortalUserFactory::createOne();
        $venue = $this->waiting('Seehaus', $manager);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        $venueId = $venue->id();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());
        $this->client->submit($crawler->filter('#venue-delete form[name="delete"]')->form());

        self::assertResponseRedirects('/admin/locations');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venues WHERE id = ?', [$venueId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_submissions WHERE venue_id = ?', [$venueId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM portal_venue_access WHERE venue_id = ?', [$venueId]));
    }

    public function test_the_spam_link_leads_to_the_edit_page(): void
    {
        $this->logInAs();
        $venue = $this->waiting();

        $crawler = $this->client->request('GET', self::QUEUE);
        $link = $crawler->filter('#review-submissions .table__actions a')->reduce(static fn (Crawler $link): bool => 'Spam löschen' === $link->text());

        self::assertSame('/admin/locations/'.$venue->number(), $link->attr('href'));
    }

    public function test_a_manager_is_refused_on_the_staff_routes(): void
    {
        $manager = $this->createManager();
        $venue = $this->waiting('Seehaus', $manager);
        $this->logInAsManager($manager);

        foreach ([['GET', '/admin/pruefung?tab=neu'], ['GET', $this->url($venue, 'ablehnen')], ['POST', $this->url($venue, 'annehmen')]] as [$method, $url]) {
            $this->client->request($method, $url, 'POST' === $method ? ['_token' => self::SAME_SITE_TOKEN] : [], server: self::SAME_ORIGIN);

            self::assertResponseRedirects('/admin/login');
        }
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    private function manager(string $firstName, string $lastName, string $email): PortalUser
    {
        return PortalUserFactory::createOne(['firstName' => $firstName, 'lastName' => $lastName, 'email' => $email]);
    }

    private function unpublished(string $name): Venue
    {
        return VenueFactory::new()->withoutLocation()->create(['name' => $name, 'status' => VenueStatus::Unpublished]);
    }

    /**
     * A location that can go online, waiting for staff. `submitted()` takes an account of its own, so the given manager
     * is written over it.
     */
    private function waiting(string $name = 'Seehaus', ?PortalUser $manager = null, bool $photo = true): Venue
    {
        $venue = $this->unpublished($name);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => 'Ein Haus am See.']);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        if ($photo) {
            VenuePhotoFactory::createOne(['venue' => $venue]);
        }
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);
        if (null !== $manager) {
            $this->connection->executeStatement('UPDATE portal_submissions SET submitted_by = ? WHERE venue_id = ?', [$manager->id(), $venue->id()]);
        }

        return $venue;
    }

    private function decide(Venue $venue): void
    {
        $this->client->request('POST', $this->url($venue, 'annehmen'), ['_token' => self::SAME_SITE_TOKEN], server: self::SAME_ORIGIN);
    }

    /**
     * On the reject page, which the client has open.
     */
    private function reject(): void
    {
        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->filter('form[name="reject_submission"]')->form(), ['reject_submission[reason]' => self::REASON]);
    }

    private function url(Venue $venue, string $action): string
    {
        return '/admin/pruefung/neu/'.$venue->number().'/'.$action;
    }

    private function venueStatus(Venue $venue): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM venues WHERE id = ?', [$venue->id()]);
    }

    private function submissionStatus(Venue $venue): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM portal_submissions WHERE venue_id = ?', [$venue->id()]);
    }
}
