<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\MediaRightsGrantFactory;
use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * G1 in the back office: staff vouch for the photos they upload, see what is granted on the "Bildrechte" card of the
 * Fotos tab, grant what waits, and revoke a grant (which removes the photo). The tests read the HTML the editor gets
 * and the rows the actions leave.
 */
final class PhotoRightsTest extends WebTestCase
{
    use AdminSession {
        tearDown as private endAdminSession;
    }
    use Factories;
    use SendsVenueMediaRequests;

    private const string MANAGER = '019a0000-0000-7000-8000-0000000000aa';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endAdminSession();
    }

    public function test_a_staff_upload_is_granted_by_attestation_and_public_at_once(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $number = $venue->number();

        $this->upload('/admin/api/cms/venues/'.$number.'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(201);
        $photo = (string) $this->answer()['photo'];
        $grant = $this->connection->fetchAssociative(
            'SELECT grantor_type, grantor_ref, evidence, revoked_at, scope_derivatives, policy_version FROM media_rights_grants WHERE venue_photo_id = ?',
            [$photo],
        );
        self::assertIsArray($grant);
        self::assertSame(['staff', $staff['user']->id(), 'staff_attestation', null, false], [$grant['grantor_type'], $grant['grantor_ref'], $grant['evidence'], $grant['revoked_at'], $grant['scope_derivatives']]);
        self::assertNotSame('legacy', $grant['policy_version'], 'the grant names the declaration text in force');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rights_policy_versions WHERE version = ?', [$grant['policy_version']]));

        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($photo, (string) $this->client->getResponse()->getContent(), 'the photo is on the public page');
    }

    public function test_removing_a_photo_in_the_island_ends_its_grant_with_the_staff_member_as_revoker(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $this->json('DELETE', '/admin/api/cms/venues/'.$venue->number().'/photos/'.$photo->id(), token: $this->csrfToken());

        self::assertResponseIsSuccessful();
        $grant = $this->connection->fetchAssociative('SELECT venue_photo_id, revoked_by_type, revoked_by_ref, revoked_at FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]);
        self::assertIsArray($grant);
        self::assertNull($grant['venue_photo_id'], 'the photo is gone, the grant stays as evidence');
        self::assertSame(['staff', $staff['user']->id()], [$grant['revoked_by_type'], $grant['revoked_by_ref']]);
        self::assertNotNull($grant['revoked_at']);
    }

    public function test_the_card_lists_every_stored_photo_with_its_status_and_the_actions(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Rittersaal']);
        $declared = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne([
            'photo' => $declared,
            'grantor' => Grantor::manager(self::MANAGER),
            'evidence' => GrantEvidence::Declaration,
            'derivatives' => true,
            'at' => new \DateTimeImmutable('2026-11-07 12:00:00 UTC'),
        ]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 3]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'room' => $room, 'position' => 1]);

        $crawler = $this->client->request('GET', $this->fotos($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Bildrechte', $crawler->filter('section[aria-labelledby="bildrechte"] h2')->text());
        $rows = $crawler->filter('#photo-rights tbody tr')->each(static fn (Crawler $row): array => [
            $row->filter('td')->eq(0)->text(),
            $row->filter('.photo-rights__status')->text(),
            $row->filter('.photo-rights__action')->count() > 0 ? $row->filter('.photo-rights__action')->text() : '',
        ]);
        self::assertCount(4, $rows);
        self::assertSame(['Foto 1', 'Freigegeben am 07.11.2026, Nachweis: Erklärung Auch für Werbezwecke freigegeben.', 'Freigabe widerrufen'], $rows[0]);
        self::assertSame('Foto 2', $rows[1][0]);
        self::assertMatchesRegularExpression('/^Freigegeben am \d{2}\.\d{2}\.\d{4}, Nachweis: Bestätigung durch das Team$/', $rows[1][1]);
        self::assertSame(['Foto 3', 'Nicht freigegeben nicht öffentlich', ''], $rows[2]);
        self::assertSame(['Foto 1 (Raum Rittersaal)', 'Nicht freigegeben nicht öffentlich', ''], $rows[3]);

        self::assertSame(
            ['Freigabe widerrufen: Foto 1', 'Freigabe widerrufen: Foto 2'],
            $crawler->filter('#photo-rights button[command="show-modal"]')->each(static fn (Crawler $button): string => (string) $button->attr('aria-label')),
            'only a granted photo has the button',
        );
        self::assertCount(2, $crawler->filter('dialog[id^="photo-revoke-"]'));
        $dialog = $crawler->filter('dialog[id="photo-revoke-'.$declared->id().'"]');
        self::assertSame('Freigabe für Foto 1 widerrufen?', $dialog->filter('.modal__title')->text());
        self::assertSame('Das Foto wird von der Website entfernt und kann nicht wiederhergestellt werden. Die Freigabe bleibt als Nachweis gespeichert.', $dialog->filter('.modal__body')->text());
        self::assertSame('/admin/locations/'.$venue->number().'/fotos/'.$declared->id().'/freigabe-widerrufen', $dialog->filter('form[method="post"]')->attr('action'));
        self::assertNotSame('', (string) $dialog->filter('input[name="_token"]')->attr('value'));

        $grantAll = $crawler->filter('form[action="/admin/locations/'.$venue->number().'/fotos/freigeben"]');
        self::assertCount(1, $grantAll);
        self::assertSame('Alle aktuellen Fotos freigeben', $grantAll->filter('button[type="submit"]')->text());
        self::assertStringContainsString('Du bestätigst damit, dass die Rechte an diesen 2 Fotos geklärt sind.', $crawler->filter('.photo-rights__grant-hint')->text());
    }

    public function test_with_nothing_waiting_there_is_no_grant_all_button_and_without_photos_no_card(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $empty = DetailVenues::minimal('leeres-haus', 'Leeres Haus');
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertCount(1, $crawler->filter('#photo-rights tbody tr'));
        self::assertCount(0, $crawler->filter('form[action$="/fotos/freigeben"]'));

        $crawler = $this->client->request('GET', $this->fotos($empty));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#bildrechte, #photo-rights'));
    }

    public function test_grant_all_attests_every_waiting_photo_and_purges(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        $gallery = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $inRoom = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'room' => $room, 'position' => 1]);
        $uploaded = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        MediaRightsGrantFactory::createOne(['photo' => $uploaded, 'grantor' => Grantor::manager(self::MANAGER), 'evidence' => GrantEvidence::Upload]);
        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertStringNotContainsString($gallery->id(), (string) $this->client->getResponse()->getContent(), 'the photo waits: it is on no public page');
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $purger = $this->purger();
        $purger->venues = [];
        $purger->listings = 0;

        $this->client->submit($crawler->filter('form[action="'.$this->fotos($venue).'/freigeben"]')->form());

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
        $toast = $this->client->followRedirect()->filter('.toast')->text();
        self::assertSame('2 Fotos freigegeben.', $toast);
        $grants = $this->connection->fetchAllAssociative(
            'SELECT venue_photo_id, grantor_type, grantor_ref, evidence, scope_derivatives FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NULL ORDER BY venue_photo_id',
            [$venue->id()],
        );
        $byPhoto = array_column($grants, null, 'venue_photo_id');
        self::assertCount(3, $grants);
        foreach ([$gallery->id(), $inRoom->id()] as $id) {
            self::assertSame(['staff', $staff['user']->id(), 'staff_attestation', false], [$byPhoto[$id]['grantor_type'], $byPhoto[$id]['grantor_ref'], $byPhoto[$id]['evidence'], $byPhoto[$id]['scope_derivatives']], $id);
        }
        self::assertSame(['manager', 'upload'], [$byPhoto[$uploaded->id()]['grantor_type'], $byPhoto[$uploaded->id()]['evidence']], 'a photo that had a grant keeps it');
        self::assertSame([$venue->number()], $purger->venues, 'the venue is purged once');
        self::assertSame(1, $purger->listings, 'and so are the listings');
        $audit = $this->connection->fetchAssociative("SELECT actor_id, subject_type, subject_id FROM admin_audit_log WHERE command LIKE '%GrantPhotoRights'");
        self::assertSame([$staff['user']->id(), 'venue', (string) $venue->number()], $audit ? array_values($audit) : null);

        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        self::assertStringContainsString($gallery->id(), (string) $this->client->getResponse()->getContent(), 'the granted photo is public now');
        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertCount(0, $crawler->filter('form[action$="/fotos/freigeben"]'), 'nothing waits any more');
    }

    public function test_a_second_press_with_nothing_waiting_writes_no_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $form = $crawler->filter('form[action$="/fotos/freigeben"]')->form();
        $this->client->submit($form);
        $this->client->followRedirect();
        $audits = $this->auditRows();

        $this->client->submit($form);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
        self::assertSame('Es gibt keine Fotos, die noch freigegeben werden müssen.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame($audits, $this->auditRows());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_revoke_removes_the_photo_and_the_public_page_stops_showing_it(): void
    {
        $staff = $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        $before = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($first->id(), $before);
        self::assertStringContainsString($second->id(), $before);
        $crawler = $this->client->request('GET', $this->fotos($venue));

        $this->client->submit($crawler->filter('dialog[id="photo-revoke-'.$first->id().'"] button[type="submit"]')->form());

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
        self::assertSame('Freigabe widerrufen. Das Foto ist von der Website entfernt.', $this->client->followRedirect()->filter('.toast')->text());
        self::assertSame([$second->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        $revoked = $this->connection->fetchAssociative('SELECT venue_photo_id, original_path, revoked_by_type, revoked_by_ref FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NOT NULL', [$venue->id()]);
        self::assertIsArray($revoked);
        self::assertNull($revoked['venue_photo_id']);
        self::assertSame(['staff', $staff['user']->id()], [$revoked['revoked_by_type'], $revoked['revoked_by_ref']]);
        self::assertNotSame('', $revoked['original_path'], 'the evidence still names the file');

        $this->client->request('GET', '/eventlokal/'.DetailVenues::MINIMAL_SLUG);
        $after = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($first->id(), $after, 'the revoked photo is on no public page');
        self::assertStringContainsString($second->id(), $after);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        self::assertCount(1, $crawler->filter('#photo-rights tbody tr'));
    }

    public function test_a_second_revoke_of_the_same_photo_says_it_is_gone_and_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $crawler = $this->client->request('GET', $this->fotos($venue));
        $form = $crawler->filter('dialog[id="photo-revoke-'.$first->id().'"] button[type="submit"]')->form();
        $this->client->submit($form);
        self::assertSame('Freigabe widerrufen. Das Foto ist von der Website entfernt.', $this->client->followRedirect()->filter('.toast')->text());
        $grants = $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]);
        $audits = $this->auditRows();
        $purger = $this->purger();
        $purger->venues = [];
        $purger->listings = 0;

        // A double click, or the same page open in a second tab.
        $this->client->submit($form);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Das Foto ist schon entfernt.', $crawler->filter('.toast.toast--info')->text());
        self::assertSame([$second->id()], $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame($grants, $this->connection->fetchAllAssociative('SELECT * FROM media_rights_grants WHERE venue_id = ? ORDER BY id', [$venue->id()]), 'the first revocation stays as it was');
        self::assertSame($audits, $this->auditRows());
        self::assertSame([[], 0], [$purger->venues, $purger->listings], 'nothing changed, so nothing is purged');
    }

    public function test_a_photo_of_another_venue_is_answered_like_a_removed_one_and_stays(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $other = DetailVenues::minimal('anderes-haus', 'Anderes Haus');
        $foreign = VenuePhotoFactory::createOne(['venue' => $other, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $token = $this->tokenOf($venue);

        $this->client->request('POST', $this->fotos($venue).'/'.$foreign->id().'/freigabe-widerrufen', ['_token' => $token]);

        self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
        self::assertSame('Das Foto ist schon entfernt.', $this->client->followRedirect()->filter('.toast.toast--info')->text(), 'the answer does not tell a removed photo from one of another venue');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE id = ?', [$foreign->id()]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_photo_id = ? AND revoked_at IS NULL', [$foreign->id()]));
    }

    public function test_an_unknown_venue_is_a_404_on_both_actions(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $token = $this->tokenOf($venue);

        $this->client->request('POST', '/admin/locations/99999999/fotos/freigeben', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/locations/99999999/fotos/0199f0d0-0000-7000-8000-000000000001/freigabe-widerrufen', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_missing_or_wrong_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::minimal();
        $granted = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 2]);
        $audits = $this->auditRows();

        foreach ([[], ['_token' => 'not-a-token']] as $body) {
            $this->client->request('POST', $this->fotos($venue).'/freigeben', $body);
            self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
            self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text());

            $this->client->request('POST', $this->fotos($venue).'/'.$granted->id().'/freigabe-widerrufen', $body);
            self::assertResponseRedirects($this->fotos($venue).'#bildrechte', 303);
            self::assertStringContainsString('Das hat nicht geklappt', $this->client->followRedirect()->filter('.toast')->text());
        }

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
        self::assertSame([$granted->id()], $this->connection->fetchFirstColumn('SELECT venue_photo_id FROM media_rights_grants WHERE venue_id = ? AND revoked_at IS NULL', [$venue->id()]), 'the waiting photo stays waiting');
        self::assertSame($audits, $this->auditRows());
    }

    public function test_anonymous_visitors_are_sent_to_the_login(): void
    {
        $this->client->request('POST', '/admin/locations/1/fotos/freigeben');
        self::assertResponseRedirects('/admin/login');

        $this->client->request('POST', '/admin/locations/1/fotos/0199f0d0-0000-7000-8000-000000000001/freigabe-widerrufen');
        self::assertResponseRedirects('/admin/login');
    }

    private function fotos(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number().'/fotos';
    }

    /**
     * The card's session token, as a revoke dialog prints it: the venue needs a granted photo.
     */
    private function tokenOf(Venue $venue): string
    {
        $crawler = $this->client->request('GET', $this->fotos($venue));

        return (string) $crawler->filter('dialog input[name="_token"]')->attr('value');
    }

    private function auditRows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_audit_log');
    }

    private function purger(): RecordingHttpCachePurger
    {
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);

        return $purger;
    }
}
