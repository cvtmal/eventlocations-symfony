<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\Entity\PortalUser;
use App\Portal\Event\LocationSubmitted;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Prüfen" (#45, POR-12): the page, "Veröffentlichen" and the way back online. A wizard rule sends a draft without an
 * inquiry address back to `kontakt`; the publish rule itself has no such gap.
 */
final class WizardCheckTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        setUp as private startPortalSession;
    }

    private const string ADDRESS = 'info@seehaus.example';
    private const array EVERYTHING = ['summary', 'main_building', 'photo', 'inquiry_email'];
    private const array NO_ADDRESS = ['summary', 'main_building', 'photo'];

    /** @var list<string> the subjects of every mail a submit tried to send */
    private array $mails = [];

    /** @var list<LocationSubmitted> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->startPortalSession();

        $this->dispatcher()->addListener(MessageEvent::class, function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($event->isQueued() && $message instanceof Email) {
                $this->mails[] = (string) $message->getSubject();
            }
        }, 100);
    }

    public function test_a_publish_with_something_missing_names_it_and_writes_nothing(): void
    {
        $venue = $this->draft($this->logInAsManager(), ['summary', 'main_building', 'inquiry_email']);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Schritt 6 von 6', $crawler->filter('.progress__label')->text());
        self::assertSame('Das fehlt noch', $crawler->filter('#check-missing')->text());
        $gap = $crawler->filter('section[aria-labelledby="check-missing"] li a');
        self::assertSame('Mindestens ein Foto', $gap->text());
        self::assertSame($this->url($venue, 'fotos'), $gap->attr('href'));

        $crawler = $this->press($venue);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Es fehlt: Mindestens ein Foto', $crawler->filter('.form-error')->text());
        self::assertSame('draft', $this->submissionStatus($venue));
        self::assertSame([], $this->mails);
    }

    /** The frame says "Es fehlt", so each gap reads as a noun and the word is not doubled (#45 F-m2). */
    public function test_every_gap_reads_as_a_noun_under_the_frame(): void
    {
        $venue = $this->draft($this->logInAsManager(), ['inquiry_email']);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame(['Kurztext', 'Wichtigste Art der Location', 'Mindestens ein Foto'], $crawler->filter('section[aria-labelledby="check-missing"] li a')->each(static fn (Crawler $gap): string => $gap->text()));

        $crawler = $this->press($venue);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $error = $crawler->filter('.form-error')->text();
        self::assertSame('Die Location ist noch nicht vollständig. Es fehlt: Kurztext, Wichtigste Art der Location, Mindestens ein Foto', $error);
        self::assertSame(1, substr_count($error, 'fehlt'));
    }

    public function test_the_rights_checkbox_is_required(): void
    {
        $venue = $this->draft($this->logInAsManager());

        $crawler = $this->press($venue, rights: false);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Bitte bestätige', $crawler->filter('form[name="submit_location"]')->text());
        self::assertSame('draft', $this->submissionStatus($venue));
        self::assertSame([], $this->mails);
    }

    public function test_a_draft_without_inquiry_address_goes_back_to_kontakt_on_get_and_post(): void
    {
        $venue = $this->draft($this->logInAsManager(), self::NO_ADDRESS);

        $this->client->request('GET', $this->url($venue));
        self::assertResponseRedirects($this->url($venue, 'kontakt'));

        $this->client->request('POST', $this->url($venue), ['submit_location' => ['rights' => '1']]);
        self::assertResponseRedirects($this->url($venue, 'kontakt'));
        self::assertSame('draft', $this->submissionStatus($venue));
    }

    public function test_a_rejected_location_without_inquiry_address_gets_the_hint_and_may_submit(): void
    {
        $venue = $this->venue($this->logInAsManager(), self::NO_ADDRESS);
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('/konto/locations/'.$venue->number().'#kontakt', $crawler->selectLink('Für diese Location ist keine E-Mail-Adresse für Anfragen hinterlegt. Trag sie im Kontakt ein.')->attr('href'));

        $this->press($venue);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_a_claimed_location_without_inquiry_address_may_submit(): void
    {
        $venue = $this->venue($this->logInAsManager(), self::NO_ADDRESS);

        $this->press($venue);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_submit_sets_submitted_stores_rights_and_shows_wird_geprueft(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->draft($manager);

        $this->press($venue);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        $row = $this->connection->fetchAssociative('SELECT status, submitted_by, rights_confirmed_at FROM portal_submissions WHERE venue_id = ?', [$venue->id()]);
        self::assertIsArray($row);
        self::assertSame('submitted', $row['status']);
        self::assertSame($manager->id(), $row['submitted_by']);
        self::assertNotNull($row['rights_confirmed_at']);

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wird geprüft', $crawler->filter('[role="status"]')->text());
        self::assertCount(0, $crawler->filter('form[name="submit_location"]'));
    }

    public function test_the_page_prints_the_declaration_and_offers_the_optional_second_box(): void
    {
        $venue = $this->draft($this->logInAsManager());

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="submit_location"]');
        self::assertSame('Bildrechte-Erklärung lesen', $form->filter('details.rights-details > summary')->text());
        self::assertNotNull($form->filter('details.rights-details')->attr('open'), 'the text is open: nobody ticks a declaration they had to unfold first');
        $text = $form->filter('details .rights-declaration');
        self::assertStringContainsString('Mit deiner Bestätigung erklärst du:', $text->text());
        self::assertCount(4, $text->filter('ol > li'));
        self::assertSame('Ich darf diese Location vertreten und bestätige die Bildrechte-Erklärung.', $form->filter('label[for="submit_location_rights"]')->text(), 'the box names the declaration it confirms');
        self::assertSame('Die Fotos dürfen auch für Werbezwecke von deine-eventlocation.ch verwendet werden (z. B. Newsletter, Social Media). Das ist freiwillig.', $form->filter('label[for="submit_location_derivatives"]')->text());
        self::assertNull($form->filter('#submit_location_derivatives')->attr('required'));
        self::assertNull($form->filter('#submit_location_derivatives')->attr('checked'));
    }

    public function test_submit_is_the_declaration_for_the_photos_that_have_no_grant(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->draft($manager);
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $this->press($venue, derivatives: true);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        $row = $this->connection->fetchAssociative('SELECT status, rights_confirmed_at FROM portal_submissions WHERE venue_id = ?', [$venue->id()]);
        self::assertIsArray($row);
        self::assertSame('submitted', $row['status']);
        self::assertNotNull($row['rights_confirmed_at'], 'the confirmation of the rights is stored as before');
        $grant = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, scope_derivatives, policy_version, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]);
        self::assertIsArray($grant);
        self::assertSame(['manager', $manager->id(), 'declaration', true, null], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['scope_derivatives'], $grant['revoked_at']]);
        self::assertNotSame('legacy', $grant['policy_version']);
        self::assertSame(
            ['staff_attestation'],
            $this->connection->fetchFirstColumn('SELECT evidence FROM media_rights_grants WHERE venue_id = ? AND venue_photo_id <> ? AND revoked_at IS NULL', [$venue->id(), $waiting->id()]),
            'the photo that had a grant keeps it',
        );
        $audit = $this->connection->fetchAssociative("SELECT actor_type, portal_user_id, subject_type, subject_id, changes FROM admin_audit_log WHERE command LIKE '%GrantPhotoRights'");
        self::assertIsArray($audit);
        self::assertSame(['portal_user', $manager->id(), 'venue', (string) $venue->number()], [$audit['actor_type'], $audit['portal_user_id'], $audit['subject_type'], $audit['subject_id']]);
        self::assertEquals(['grantor' => 'manager', 'evidence' => 'declaration', 'derivatives' => true, 'photos' => 'all'], json_decode((string) $audit['changes'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_without_the_second_box_the_grants_record_no_derivatives(): void
    {
        $venue = $this->draft($this->logInAsManager());
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $this->press($venue);

        self::assertFalse($this->connection->fetchOne('SELECT scope_derivatives FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]));
    }

    public function test_a_resubmit_grants_only_the_photos_without_a_live_grant(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->venue($manager);
        SubmissionFactory::new()->accepted()->create(['venue' => $venue]);
        $old = $this->connection->fetchOne('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
        $new = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $before = $this->connection->fetchAssociative('SELECT id, evidence, created_at FROM media_rights_grants WHERE venue_photo_id = ?', [$old]);

        $this->press($venue);

        self::assertSame('submitted', $this->submissionStatus($venue));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NULL', [$venue->id()]));
        self::assertSame($before, $this->connection->fetchAssociative('SELECT id, evidence, created_at FROM media_rights_grants WHERE venue_photo_id = ?', [$old]), 'the earlier grant is not touched');
        self::assertSame('declaration', $this->connection->fetchOne('SELECT evidence FROM media_rights_grants WHERE venue_photo_id = ?', [$new->id()]));
    }

    public function test_a_press_with_every_photo_granted_writes_no_grant_row_and_no_audit_row(): void
    {
        $venue = $this->draft($this->logInAsManager());
        $grants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]);

        $this->press($venue);

        self::assertSame('submitted', $this->submissionStatus($venue));
        self::assertSame($grants, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE command LIKE '%GrantPhotoRights'"), 'a command that grants nothing would only be noise in the history');
    }

    public function test_a_refused_press_grants_nothing(): void
    {
        $venue = $this->draft($this->logInAsManager(), ['inquiry_email', 'main_building']);
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);

        $this->press($venue);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('draft', $this->submissionStatus($venue));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]));
    }

    public function test_the_unticked_rights_box_grants_nothing(): void
    {
        $venue = $this->draft($this->logInAsManager());
        $waiting = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $this->press($venue, rights: false, derivatives: true);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$waiting->id()]));
    }

    public function test_submit_sends_two_mails_and_fires_the_event(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->draft($manager);
        $this->recordTheEvent();

        $this->press($venue);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertSame(['Wir prüfen '.$venue->name(), 'Neue Location zur Prüfung: '.$venue->name()], $this->mails);
        self::assertEmailCount(2);
        self::assertCount(1, $this->events);
        self::assertSame($venue->id(), $this->events[0]->venueId);
        self::assertSame($venue->name(), $this->events[0]->venueName);
        self::assertSame($manager->email(), $this->events[0]->email);
        self::assertSame($manager->firstName(), $this->events[0]->firstName);
        self::assertSame($manager->lastName(), $this->events[0]->lastName);
    }

    public function test_a_failing_mail_does_not_stop_the_event(): void
    {
        $venue = $this->draft($this->logInAsManager());
        $this->recordTheEvent();
        $this->failTheNotice();

        $this->press($venue);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertCount(1, $this->events);
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_a_second_post_sends_nothing(): void
    {
        $venue = $this->draft($this->logInAsManager());
        $form = $this->form($venue);

        $this->client->submit($form, ['submit_location[rights]' => '1']);
        $this->client->submit($form, ['submit_location[rights]' => '1']);

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertCount(2, $this->mails);
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_editing_stays_open_during_the_review(): void
    {
        $venue = $this->venue($this->logInAsManager());
        SubmissionFactory::new()->submitted()->create(['venue' => $venue]);

        $form = $this->client->request('GET', $this->url($venue, 'texte'))->filter('form[name="venue_texts"]')->form();
        $this->client->submit($form, ['venue_texts[texts][de][summary]' => 'Während der Prüfung geändert.']);

        self::assertResponseRedirects($this->url($venue, 'merkmale'), Response::HTTP_SEE_OTHER);
        self::assertSame('Während der Prüfung geändert.', $this->connection->fetchOne("SELECT summary FROM venue_translations WHERE venue_id = ? AND locale = 'de'", [$venue->id()]));
        self::assertSame('submitted', $this->submissionStatus($venue));
    }

    public function test_the_rejected_reason_is_shown(): void
    {
        $venue = $this->venue($this->logInAsManager());
        SubmissionFactory::new()->rejected()->create(['venue' => $venue]);

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Die Angaben reichen nicht aus.', $crawler->filter('section[aria-labelledby="check-rejected"] .card__body')->text());
        self::assertSame('Veröffentlichen', $this->button($crawler));
    }

    public function test_the_button_says_wieder_veroeffentlichen_for_an_offline_location(): void
    {
        $manager = $this->logInAsManager();
        $accepted = $this->venue($manager);
        SubmissionFactory::new()->accepted()->create(['venue' => $accepted]);
        $claimed = $this->venue($manager);
        $draft = $this->draft($manager);

        self::assertSame('Wieder veröffentlichen', $this->button($this->client->request('GET', $this->url($accepted))));
        self::assertSame('Wieder veröffentlichen', $this->button($this->client->request('GET', $this->url($claimed))));
        self::assertSame('Veröffentlichen', $this->button($this->client->request('GET', $this->url($draft))));
    }

    /**
     * An unpublished location of `$manager` without a submission row, holding only the given gap codes.
     *
     * @param list<string> $has
     */
    private function venue(PortalUser $manager, array $has = self::EVERYTHING): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'summary' => \in_array('summary', $has, true) ? 'Ein Haus am See.' : '']);
        if (\in_array('main_building', $has, true)) {
            VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        }
        if (\in_array('photo', $has, true)) {
            VenuePhotoFactory::createOne(['venue' => $venue]);
        }
        if (\in_array('inquiry_email', $has, true)) {
            $this->connection->executeStatement('UPDATE venues SET inquiry_email = ? WHERE id = ?', [self::ADDRESS, $venue->id()]);
        }
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    /**
     * @param list<string> $has
     */
    private function draft(PortalUser $manager, array $has = self::EVERYTHING): Venue
    {
        $venue = $this->venue($manager, $has);
        SubmissionFactory::createOne(['venue' => $venue]);

        return $venue;
    }

    private function press(Venue $venue, bool $rights = true, bool $derivatives = false): Crawler
    {
        return $this->client->submit($this->form($venue), ($rights ? ['submit_location[rights]' => '1'] : []) + ($derivatives ? ['submit_location[derivatives]' => '1'] : []));
    }

    private function form(Venue $venue): Form
    {
        return $this->client->request('GET', $this->url($venue))->filter('form[name="submit_location"]')->form();
    }

    private function button(Crawler $crawler): string
    {
        return $crawler->filter('form[name="submit_location"] button[type="submit"]')->text();
    }

    private function recordTheEvent(): void
    {
        $this->dispatcher()->addListener(LocationSubmitted::class, function (LocationSubmitted $event): void {
            $this->events[] = $event;
        });
    }

    private function failTheNotice(): void
    {
        $this->dispatcher()->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            $message = $event->getMessage();
            if ($event->isQueued() && $message instanceof Email && str_starts_with((string) $message->getSubject(), 'Neue Location zur Prüfung')) {
                throw new TransportException('The mail server is gone.');
            }
        }, 50);
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function submissionStatus(Venue $venue): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM portal_submissions WHERE venue_id = ?', [$venue->id()]);
    }

    private function url(Venue $venue, string $step = 'pruefen'): string
    {
        return '/konto/locations/'.$venue->number().'/einrichten/'.$step;
    }
}
