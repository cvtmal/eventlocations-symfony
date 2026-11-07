<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenuePhotoFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * G1 on the manager's Fotos tab: a photo a manager uploads waits for the declaration, and is public at once once one is
 * on record; the declaration form grants what waits; "Freigabe widerrufen" removes a photo. The tests read the HTML the
 * manager gets and the rows the actions leave.
 */
final class PhotoRightsTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    private const string FORM = 'photo_rights_declaration';
    private const string DERIVATIVES = 'Die Fotos dürfen auch für Werbezwecke von deine-eventlocation.ch verwendet werden (z. B. Newsletter, Social Media). Das ist freiwillig.';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_an_upload_before_any_declaration_is_stored_but_not_public_and_the_form_publishes_it(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $photo = (string) $this->answer()['photo'];
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$photo]), 'no grant: the photo waits for the declaration');
        self::assertStringNotContainsString($photo, $this->publicPage(), 'a photo that waits is on no public page');

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Neue Fotos sind erst öffentlich, wenn du die Bildrechte-Erklärung bestätigt hast.', $crawler->filter('#bildrechte-hinweis')->text());
        self::assertSame(['Foto 1', 'Nicht freigegeben nicht öffentlich'], $crawler->filter('#photo-rights tbody tr td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertCount(0, $crawler->filter('#photo-rights .photo-rights__action'), 'a photo that waits has nothing to revoke');

        $this->client->submit($crawler->filter('form[name="'.self::FORM.'"]')->form(), [self::FORM.'[declaration]' => '1', self::FORM.'[derivatives]' => '1']);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        self::assertSame('1 Foto freigegeben.', $this->client->followRedirect()->filter('.toast')->text());
        $grant = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, scope_derivatives, policy_version, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$photo]);
        self::assertIsArray($grant);
        self::assertSame(['manager', $manager->id(), 'declaration', true, null], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['scope_derivatives'], $grant['revoked_at']]);
        self::assertNotSame('legacy', $grant['policy_version']);
        self::assertStringContainsString($photo, $this->publicPage(), 'the declaration published the photo');
        $audit = $this->connection->fetchAssociative("SELECT actor_type, actor_id, portal_user_id, subject_type, subject_id FROM admin_audit_log WHERE command LIKE '%GrantPhotoRights'");
        self::assertSame(['portal_user', null, $manager->id(), 'venue', (string) $venue->number()], $audit ? array_values($audit) : null);

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertMatchesRegularExpression('/^Neue Fotos gelten mit deiner Bildrechte-Erklärung vom \d{2}\.\d{2}\.\d{4} als freigegeben\.$/', $crawler->filter('#bildrechte-hinweis')->text());
        self::assertCount(0, $crawler->filter('form[name="'.self::FORM.'"]'), 'nothing waits any more');
        self::assertStringStartsWith('Freigegeben am ', $crawler->filter('#photo-rights .photo-rights__status')->text());
        self::assertStringContainsString('Nachweis: Erklärung', $crawler->filter('#photo-rights .photo-rights__status')->text());
    }

    public function test_an_upload_after_the_declaration_is_public_at_once_as_an_upload_grant(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);
        $declared = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne(['photo' => $declared, 'grantor' => Grantor::manager($manager->id()), 'evidence' => GrantEvidence::Declaration, 'derivatives' => true]);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $photo = (string) $this->answer()['photo'];
        $grant = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, scope_derivatives, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$photo]);
        self::assertIsArray($grant);
        self::assertSame(['manager', $manager->id(), 'upload', false, null], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['scope_derivatives'], $grant['revoked_at']], 'the derivatives choice belongs to the declaration, not to later uploads');
        self::assertStringContainsString($photo, $this->publicPage());

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertCount(0, $crawler->filter('form[name="'.self::FORM.'"]'));
        self::assertSame(2, $crawler->filter('#photo-rights tbody tr')->count());
    }

    public function test_a_colleagues_declaration_does_not_cover_my_upload_until_i_have_declared_myself(): void
    {
        $colleague = $this->createManager();
        $manager = $this->logInAsManager();
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $colleague, 'venue' => $venue]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);
        $declared = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne(['photo' => $declared, 'grantor' => Grantor::manager($colleague->id()), 'evidence' => GrantEvidence::Declaration]);

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertResponseIsSuccessful();
        self::assertSame('Neue Fotos sind erst öffentlich, wenn du die Bildrechte-Erklärung bestätigt hast. Das geht weiter unten, sobald du Fotos hochgeladen und die Seite neu geladen hast.', $crawler->filter('#bildrechte-hinweis')->text(), 'the colleague declared, I did not');

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = (string) $this->answer()['photo'];
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$first]), 'no grant: my photo waits for my declaration');
        self::assertStringNotContainsString($first, $this->publicPage());

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertCount(1, $crawler->filter('form[name="'.self::FORM.'"]'), 'the form asks me, while my photo has no live grant');
        $this->client->submit($crawler->filter('form[name="'.self::FORM.'"]')->form(), [self::FORM.'[declaration]' => '1']);
        self::assertSame('1 Foto freigegeben.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertStringContainsString($first, $this->publicPage(), 'my declaration published it');

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = (string) $this->answer()['photo'];
        $grant = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, revoked_at FROM media_rights_grants WHERE venue_photo_id = ?', [$second]);
        self::assertSame(['manager', $manager->id(), 'upload', null], $grant ? array_values($grant) : null, 'now my own declaration is on record');
        self::assertStringContainsString($second, $this->publicPage());
        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertMatchesRegularExpression('/^Neue Fotos gelten mit deiner Bildrechte-Erklärung vom \d{2}\.\d{2}\.\d{4} als freigegeben\.$/', $crawler->filter('#bildrechte-hinweis')->text());
        self::assertSame(
            [$colleague->id(), $manager->id()],
            $this->connection->fetchFirstColumn("SELECT grantor_ref FROM media_rights_grants WHERE venue_id = ? AND evidence = 'declaration' ORDER BY created_at, id", [$venue->id()]),
            'two declarations, one of each manager',
        );
    }

    public function test_the_page_shows_the_declaration_text_and_both_boxes_while_a_photo_waits(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);

        $crawler = $this->client->request('GET', $this->fotos($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Fotos', $crawler->filter('nav.tabs--record a[aria-current="page"]')->text());
        $form = $crawler->filter('form[name="'.self::FORM.'"]');
        self::assertCount(1, $form);
        self::assertSame('Bildrechte-Erklärung', $crawler->filter('section[aria-labelledby="bildrechte-erklaerung"] h2')->text());
        $text = $form->filter('.rights-declaration');
        self::assertStringContainsString('Mit deiner Bestätigung erklärst du:', $text->text());
        self::assertCount(4, $text->filter('ol > li'));
        self::assertStringContainsString('Du kannst eine Freigabe jederzeit widerrufen.', $text->text());
        self::assertSame('Ich bestätige die Bildrechte-Erklärung.', $form->filter('label[for="'.self::FORM.'_declaration"]')->text());
        self::assertSame(self::DERIVATIVES, $form->filter('label[for="'.self::FORM.'_derivatives"]')->text());
        self::assertSame('checkbox', $form->filter('#'.self::FORM.'_declaration')->attr('type'));
        self::assertSame('checkbox', $form->filter('#'.self::FORM.'_derivatives')->attr('type'));
        self::assertNull($form->filter('#'.self::FORM.'_derivatives')->attr('required'), 'the second box is optional');
        self::assertSame('Fotos freigeben', $form->filter('button[type="submit"]')->text());
        self::assertNotSame('', (string) $form->filter('input[name="'.self::FORM.'[_token]"]')->attr('value'));
    }

    public function test_the_declaration_without_the_derivatives_box_records_no_derivatives(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);
        $first = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $crawler = $this->client->request('GET', $this->fotos($venue));

        $this->client->submit($crawler->filter('form[name="'.self::FORM.'"]')->form(), [self::FORM.'[declaration]' => '1']);

        self::assertSame('2 Fotos freigegeben.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(
            [[$first->id(), 'declaration', false], [$second->id(), 'declaration', false]],
            array_map(
                static fn (array $row): array => [$row['venue_photo_id'], $row['evidence'], $row['scope_derivatives']],
                $this->connection->fetchAllAssociative('SELECT venue_photo_id, evidence, scope_derivatives FROM media_rights_grants WHERE venue_id = ? ORDER BY venue_photo_id', [$venue->id()]),
            ),
        );
    }

    public function test_the_form_refuses_an_unticked_box_with_a_422_and_grants_nothing(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $crawler = $this->client->request('GET', $this->fotos($venue));

        $crawler = $this->client->submit($crawler->filter('form[name="'.self::FORM.'"]')->form(), [self::FORM.'[derivatives]' => '1']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Bitte bestätige die Bildrechte-Erklärung, damit wir die Fotos freigeben können.', $crawler->filter('#'.self::FORM.'_declaration_error')->text());
        self::assertSame('Fotos', $crawler->filter('nav.tabs--record a[aria-current="page"]')->text(), 'the Fotos tab stays marked');
        self::assertSame('true', $crawler->filter('#'.self::FORM.'_declaration')->attr('aria-invalid'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_the_form_needs_its_token(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $this->client->request('GET', $this->fotos($venue));

        $this->client->request('POST', $this->fotos($venue), [self::FORM => ['declaration' => '1']]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_second_press_with_nothing_waiting_grants_and_audits_nothing(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $form = $crawler->filter('form[name="'.self::FORM.'"]')->form();
        $this->client->submit($form, [self::FORM.'[declaration]' => '1']);
        $this->client->followRedirect();
        $audits = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log');

        $this->client->submit($form, [self::FORM.'[declaration]' => '1']);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        self::assertSame('Alle Fotos sind schon freigegeben.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame($audits, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_revoke_removes_the_photo_and_records_the_manager(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        self::assertStringContainsString($first->id(), $this->publicPage());
        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertSame(
            ['Freigabe widerrufen: Foto 1', 'Freigabe widerrufen: Foto 2'],
            $crawler->filter('#photo-rights button[command="show-modal"]')->each(static fn (Crawler $button): string => (string) $button->attr('aria-label')),
        );
        $dialog = $crawler->filter('dialog[id="photo-revoke-'.$first->id().'"]');
        self::assertSame('Das Foto wird von der Website entfernt und kann nicht wiederhergestellt werden. Die Freigabe bleibt als Nachweis gespeichert.', $dialog->filter('.modal__body')->text());

        $this->client->submit($dialog->filter('button[type="submit"]')->form());

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        self::assertSame('Freigabe widerrufen. Das Foto ist von der Website entfernt.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame([$second->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        $revoked = $this->connection->fetchAssociative('SELECT venue_photo_id, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NOT NULL', [$venue->id()]);
        self::assertIsArray($revoked);
        self::assertNull($revoked['venue_photo_id']);
        self::assertSame(['manager', $manager->id()], [$revoked['revoked_by_type'], $revoked['revoked_by_ref']]);
        $page = $this->publicPage();
        self::assertStringNotContainsString($first->id(), $page);
        self::assertStringContainsString($second->id(), $page);
    }

    public function test_a_second_revoke_of_the_same_photo_says_it_is_gone_and_changes_nothing(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $form = $crawler->filter('dialog[id="photo-revoke-'.$first->id().'"] button[type="submit"]')->form();
        $this->client->submit($form);
        self::assertSame('Freigabe widerrufen. Das Foto ist von der Website entfernt.', $this->client->followRedirect()->filter('.toast')->text());
        $grants = $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]);
        $audits = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log');

        // A double click, or the same page open in a second tab.
        $this->client->submit($form);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Das Foto ist schon entfernt.', $crawler->filter('.toast.toast--info')->text());
        self::assertSame([$second->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame($grants, $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]), 'the first revocation stays as it was');
        self::assertSame($audits, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log'));
    }

    public function test_the_islands_remove_ends_the_grant_with_the_manager_as_revoker(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->managedVenue($manager);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $this->json('DELETE', '/konto/api/locations/'.$venue->number().'/photos/'.$photo->id(), token: $this->csrfToken());

        self::assertResponseIsSuccessful();
        $revoked = $this->connection->fetchAssociative('SELECT revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NOT NULL', [$venue->id()]);
        self::assertSame(['manager', $manager->id()], $revoked ? array_values($revoked) : null);
    }

    public function test_a_revoke_needs_its_token(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $this->client->request('GET', $this->fotos($venue));

        $this->client->request('POST', $this->fotos($venue).'/'.$photo->id().'/freigabe-widerrufen');

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        self::assertSame('Das hat nicht geklappt. Bitte versuch es noch einmal.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE id = ?', [$photo->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ? AND revoked_at IS NULL', [$photo->id()]));
    }

    public function test_a_photo_of_another_location_is_answered_like_a_removed_one_and_stays(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $other = DetailVenues::minimal('anderes-haus', 'Anderes Haus');
        $foreign = VenuePhotoFactory::createOne(['venue' => $other, 'position' => 1]);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $token = (string) $crawler->filter('dialog input[name="_token"]')->attr('value');

        $this->client->request('POST', $this->fotos($venue).'/'.$foreign->id().'/freigabe-widerrufen', ['_token' => $token]);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', Response::HTTP_SEE_OTHER);
        self::assertSame('Das Foto ist schon entfernt.', $this->client->followRedirect()->filter('.toast.toast--info')->text(), 'the answer does not tell a removed photo from one of another location');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE id = ?', [$foreign->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ? AND revoked_at IS NULL', [$foreign->id()]));
    }

    public function test_another_managers_location_is_refused_on_both_posts_and_changes_nothing(): void
    {
        $this->logInAsManager();
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['venue' => $venue]);
        $granted = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $this->client->request('GET', '/konto/profil');

        $this->client->request('POST', $this->fotos($venue), [self::FORM => ['declaration' => '1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', $this->fotos($venue).'/'.$granted->id().'/freigabe-widerrufen', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('GET', $this->fotos($venue));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame([$granted->id()], $this->connection->fetchFirstColumn('SELECT venue_photo_id FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NULL', [$venue->id()]));
    }

    public function test_a_location_without_stored_photos_has_no_card_and_no_form(): void
    {
        $venue = $this->managedVenue($this->logInAsManager());

        $crawler = $this->client->request('GET', $this->fotos($venue));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#photo-rights, form[name="'.self::FORM.'"]'));
        self::assertCount(1, $crawler->filter('#bildrechte-hinweis'), 'the note about uploads is there from the start');
    }

    private function managedVenue(PortalUser $manager): Venue
    {
        $venue = DetailVenues::minimal();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    private function fotos(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/fotos';
    }

    /**
     * The public detail page, whoever asks: the photo ids are in its `<picture>` URLs.
     */
    private function publicPage(): string
    {
        $this->client->request('GET', '/hochzeitslocation/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * A real page's token, as the island reads it. Beats the trait's, which reads `/admin`.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/konto/profil');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }
}
