<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\RoomFactory;
use App\Factory\RoomTranslationFactory;
use App\Factory\VenueFactory;
use App\Factory\VenueTermFactory;
use App\Factory\VenueTranslationFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Venues\DetailVenues;
use App\Tests\Support\Venues\PublicPhotos;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Command\ReportContent;
use App\Venues\Command\TakeEntryOffline;
use App\Venues\Entity\Venue;
use App\Venues\Exception\ModerationCaseAlreadyDecided;
use App\Venues\Exception\ModerationCaseNotFound;
use App\Venues\Exception\ModerationCaseWithoutVenue;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\PhotoGrant;
use App\Venues\ValueObject\TermGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * G2's three commands on real uploads, in a rolled-back transaction: a complaint hides only public photos of its
 * venue, `keep` lifts the hold, `remove` revokes through G1 (grant ended by staff, photo and files gone), a decision
 * is final, and an entry a case took offline comes back with `keep` and stays offline with `remove`.
 */
final class ModerationCommandsTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;
    use ImportsTownDataset;

    private const string ADMIN = '019a0000-0000-7000-8000-0000000000ad';

    public function test_a_rights_complaint_hides_only_public_photos_of_its_venue(): void
    {
        $venue = $this->venue('Seehaus Meldung', 'seehaus-meldung');
        $other = $this->venue('Anderes Haus', 'anderes-haus');
        $number = $venue->number();
        [$named, $kept] = [$this->grantedPhoto($number), $this->grantedPhoto($number)];
        $private = $this->addPhoto($number, $this->upload())->photoId;
        $foreign = $this->grantedPhoto($other->number());
        $this->connection->executeStatement('UPDATE venues SET cms_edited_at = NULL WHERE number = ?', [$number]);
        $this->resetPurger();

        $held = $this->report($number, [$named, $private, $foreign, 'kein-uuid', $named]);

        self::assertSame(1, $held, 'the ungranted photo, the other venue\'s photo, the junk and the repeat hold nothing');
        self::assertSame([$named], $this->linkedPhotos());
        self::assertSame([$kept], $this->seen($number)['detail']);
        self::assertSame([$foreign], $this->seen($other->number())['detail'], 'the other venue keeps its photo');
        self::assertSame([$number], $this->purger()->venues);
        self::assertGreaterThan(0, $this->purger()->listings);

        $case = $this->connection->fetchAssociative('SELECT kind, status, venue_reference, complainant_name, complainant_email, source_url, good_faith_at IS NOT NULL AS confirmed FROM moderation_cases');
        self::assertSame(['kind' => 'rights', 'status' => 'open', 'venue_reference' => null, 'complainant_name' => 'Erika Beispiel', 'complainant_email' => 'erika@example.ch', 'source_url' => '/eventlokal/seehaus-meldung', 'confirmed' => true], $case);
        self::assertSame(
            [['actor_type' => 'console', 'subject_type' => 'moderation_case', 'changes' => '{"kind": "rights", "venue": '.$number.', "photos": 5}']],
            $this->connection->fetchAllAssociative("SELECT actor_type, subject_type, changes::text AS changes FROM admin_audit_log WHERE command = 'App\\Venues\\Command\\ReportContent'"),
        );
        self::assertFalse($this->cmsEdited($number), 'a complaint is no edit: the import still owns the venue');
    }

    public function test_only_a_rights_complaint_names_photos(): void
    {
        $venue = $this->venue('Seehaus Korrektur', 'seehaus-korrektur');
        $photo = $this->grantedPhoto($venue->number());
        $this->resetPurger();

        $held = $this->report($venue->number(), [$photo], ModerationKind::Correction);

        self::assertSame(0, $held);
        self::assertSame([], $this->linkedPhotos());
        self::assertSame([$photo], $this->seen($venue->number())['detail']);
        self::assertSame([], $this->purger()->venues, 'nothing public changed, so nothing is purged');
    }

    public function test_a_complaint_without_a_venue_keeps_what_was_typed(): void
    {
        $this->dispatch(new ReportContent(EntityId::generate(), ModerationKind::Removal, null, 'Gasthaus zur Linde, Bern', [], 'Max', 'max@example.ch', 'Bitte entfernen.', null));

        self::assertSame(
            ['venue_id' => null, 'venue_reference' => 'Gasthaus zur Linde, Bern', 'kind' => 'removal'],
            $this->connection->fetchAssociative('SELECT venue_id, venue_reference, kind FROM moderation_cases'),
        );
    }

    public function test_keep_lifts_the_hold(): void
    {
        $venue = $this->venue('Seehaus Behalten', 'seehaus-behalten');
        $number = $venue->number();
        $photo = $this->grantedPhoto($number);
        $caseId = $this->reportCase($number, [$photo]);
        self::assertSame([], $this->seen($number)['detail']);
        $this->resetPurger();

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Keep, 'Der Betreiber hat die Rechte belegt.', self::ADMIN));
        $this->em->clear();

        self::assertSame([$photo], $this->seen($number)['detail']);
        self::assertSame([$photo], $this->seen($number)['sitemap']);
        self::assertSame([$number], $this->purger()->venues);
        self::assertSame(
            ['status' => 'decided', 'decision' => 'keep', 'decision_note' => 'Der Betreiber hat die Rechte belegt.', 'decided_by' => self::ADMIN, 'decided' => true],
            $this->connection->fetchAssociative('SELECT status, decision, decision_note, decided_by, decided_at IS NOT NULL AS decided FROM moderation_cases'),
        );
    }

    public function test_remove_revokes_the_grant_and_deletes_the_photo_and_its_files(): void
    {
        $venue = $this->venue('Seehaus Entfernen', 'seehaus-entfernen');
        $number = $venue->number();
        $source = $this->upload();
        $photo = $this->addPhoto($number, $source, grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation))->photoId;
        $other = $this->grantedPhoto($number);
        $original = 'cms/'.$number.'/'.hash_file('sha256', $source).'.jpg';
        $files = glob($this->paths->photoDirectory($number).'/'.$photo.'-*') ?: [];
        self::assertNotSame([], $files);
        $caseId = $this->reportCase($number, [$photo]);

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Remove, null, self::ADMIN));
        $this->em->clear();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE id = ?', [$photo]));
        self::assertSame([$other], $this->seen($number)['detail']);
        self::assertSame([], glob($this->paths->photoDirectory($number).'/'.$photo.'-*') ?: [], 'every variant is gone');
        self::assertFileDoesNotExist($this->paths->original($original));
        self::assertSame(
            ['revoked_by_type' => 'staff', 'revoked_by_ref' => self::ADMIN, 'venue_photo_id' => null, 'revoked' => true],
            $this->connection->fetchAssociative('SELECT revoked_by_type, revoked_by_ref, venue_photo_id, revoked_at IS NOT NULL AS revoked FROM media_rights_grants WHERE original_path = ?', [$original]),
        );
        self::assertSame(
            [['venue_photo_id' => null, 'original_path' => $original]],
            $this->connection->fetchAllAssociative('SELECT venue_photo_id, original_path FROM moderation_case_photos'),
            'the case still names the file',
        );
        self::assertSame(['status' => 'decided', 'decision' => 'remove'], $this->connection->fetchAssociative('SELECT status, decision FROM moderation_cases'));
        self::assertContains('App\Venues\Command\RemoveVenuePhoto', array_column($this->auditRows($number), 'command'));
    }

    public function test_a_decided_case_is_not_decided_again(): void
    {
        $venue = $this->venue('Seehaus Doppelt', 'seehaus-doppelt');
        $caseId = $this->reportCase($venue->number(), [$this->grantedPhoto($venue->number())]);
        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        self::assertInstanceOf(ModerationCaseAlreadyDecided::class, $this->failure(new DecideModerationCase($caseId, ModerationDecision::Remove, null, self::ADMIN)));
        self::assertInstanceOf(ModerationCaseAlreadyDecided::class, $this->failure(new TakeEntryOffline($caseId, self::ADMIN)));
        self::assertSame('keep', $this->connection->fetchOne('SELECT decision FROM moderation_cases'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]), 'the second decision removed nothing');
    }

    public function test_an_unknown_case_is_not_found(): void
    {
        self::assertInstanceOf(ModerationCaseNotFound::class, $this->failure(new DecideModerationCase(EntityId::generate(), ModerationDecision::Keep, null, self::ADMIN)));
        self::assertInstanceOf(ModerationCaseNotFound::class, $this->failure(new TakeEntryOffline('kein-uuid', self::ADMIN)));
    }

    public function test_an_entry_taken_offline_comes_back_with_keep(): void
    {
        $venue = $this->publishable('Seehaus Offline', 'seehaus-offline');
        $number = $venue->number();
        $caseId = $this->reportCase($number, [], ModerationKind::Removal);

        self::assertTrue($this->dispatch(new TakeEntryOffline($caseId, self::ADMIN)));
        self::assertFalse($this->reload($number)->isPublished());
        self::assertTrue((bool) $this->connection->fetchOne('SELECT took_entry_offline FROM moderation_cases'));
        self::assertFalse($this->dispatch(new TakeEntryOffline($caseId, self::ADMIN)), 'an entry that is offline already is left alone');

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        self::assertTrue($this->reload($number)->isPublished());
        $commands = array_column($this->auditRows($number), 'command');
        self::assertContains('App\Venues\Command\UnpublishVenue', $commands);
        self::assertContains('App\Venues\Command\PublishVenue', $commands);
    }

    /** F14 (D54): `keep` publishes through the draft rule, so an entry nobody manages with unchecked facts stays offline and the case stays open. */
    public function test_keep_cannot_bring_back_an_unmanaged_entry_whose_facts_are_unchecked(): void
    {
        $venue = $this->publishable('Seehaus Ungeprüft', 'seehaus-ungeprueft');
        $number = $venue->number();
        $caseId = $this->reportCase($number, [], ModerationKind::Removal);
        self::assertTrue($this->dispatch(new TakeEntryOffline($caseId, self::ADMIN)));
        $this->connection->executeStatement('UPDATE venues SET facts_checked_at = NULL WHERE number = ?', [$number]);

        $failure = $this->failure(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        self::assertInstanceOf(VenueRuleViolation::class, $failure);
        self::assertSame(['facts'], $failure->missing);
        self::assertFalse($this->reload($number)->isPublished());
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM moderation_cases WHERE id = ?', [$caseId]));
    }

    /** D60: an entry that lost its inquiry address while offline cannot come back with `keep`; the case stays open. */
    public function test_keep_cannot_bring_back_an_entry_without_an_inquiry_address(): void
    {
        $venue = $this->publishable('Seehaus Ohne Adresse', 'seehaus-ohne-adresse');
        $number = $venue->number();
        $caseId = $this->reportCase($number, [], ModerationKind::Removal);
        self::assertTrue($this->dispatch(new TakeEntryOffline($caseId, self::ADMIN)));
        $this->connection->executeStatement('UPDATE venues SET inquiry_email = NULL WHERE number = ?', [$number]);

        $failure = $this->failure(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        self::assertInstanceOf(VenueRuleViolation::class, $failure);
        self::assertSame(['inquiry_email'], $failure->missing);
        self::assertFalse($this->reload($number)->isPublished());
        self::assertSame('open', $this->connection->fetchOne('SELECT status FROM moderation_cases WHERE id = ?', [$caseId]));
    }

    public function test_an_entry_taken_offline_stays_offline_with_remove(): void
    {
        $venue = $this->publishable('Seehaus Weg', 'seehaus-weg');
        $number = $venue->number();
        $caseId = $this->reportCase($number, [], ModerationKind::Removal);
        $this->dispatch(new TakeEntryOffline($caseId, self::ADMIN));

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Remove, null, self::ADMIN));

        self::assertFalse($this->reload($number)->isPublished());
    }

    public function test_keep_does_not_publish_an_entry_the_case_did_not_take_offline(): void
    {
        $venue = $this->publishable('Seehaus Entwurf', 'seehaus-entwurf');
        $number = $venue->number();
        $caseId = $this->reportCase($number, [], ModerationKind::Removal);
        $this->connection->executeStatement("UPDATE venues SET status = 'unpublished' WHERE number = ?", [$number]);

        self::assertFalse($this->dispatch(new TakeEntryOffline($caseId, self::ADMIN)));
        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Keep, null, self::ADMIN));

        self::assertFalse($this->reload($number)->isPublished());
    }

    public function test_a_case_without_a_venue_cannot_take_an_entry_offline(): void
    {
        $caseId = EntityId::generate();
        $this->dispatch(new ReportContent($caseId, ModerationKind::Removal, null, 'Irgendwo', [], 'Max', 'max@example.ch', 'Bitte entfernen.', null));

        self::assertInstanceOf(ModerationCaseWithoutVenue::class, $this->failure(new TakeEntryOffline($caseId, self::ADMIN)));
    }

    public function test_a_complaint_hides_every_row_of_the_named_file_and_remove_takes_them_all(): void
    {
        $venue = $this->venue('Seehaus Doppelt Gespeichert', 'seehaus-doppelt-gespeichert');
        $number = $venue->number();
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        RoomTranslationFactory::createOne(['room' => $room, 'name' => 'Saal']);
        $source = $this->upload();
        $grant = new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation);
        $gallery = $this->addPhoto($number, $source, grant: $grant)->photoId;
        $inRoom = $this->addPhoto($number, $source, $room->id(), grant: $grant)->photoId;
        $other = $this->grantedPhoto($number);
        self::assertSame([$gallery, $other], $this->seen($number)['detail']);
        self::assertSame([$inRoom], $this->seen($number)['rooms']);

        $caseId = $this->reportCase($number, [$gallery]);

        self::assertSame([$gallery, $inRoom], $this->linkedPhotos(), 'the room row of the same file is held too');
        self::assertSame([$other], $this->seen($number)['detail']);
        self::assertSame([], $this->seen($number)['rooms']);

        $this->dispatch(new DecideModerationCase($caseId, ModerationDecision::Remove, null, self::ADMIN));
        $this->em->clear();

        self::assertSame([$other], array_map(strval(...), $this->connection->fetchFirstColumn('SELECT id FROM venue_photos WHERE venue_id = ?', [$venue->id()])));
    }

    public function test_a_complaint_links_at_most_ten_photos_and_none_without_a_hold(): void
    {
        // Eleven photos fit Pro's limit only (F12).
        $venue = $this->venue('Seehaus Viele', 'seehaus-viele', KnownTiers::PRO);
        $number = $venue->number();
        $photos = [];
        for ($i = 1; $i <= 11; ++$i) {
            $photos[] = $this->grantedPhoto($number);
        }

        self::assertSame(10, $this->report($number, $photos));
        self::assertSame(\array_slice($photos, 0, 10), $this->linkedPhotos());

        $this->connection->executeStatement('DELETE FROM moderation_cases');
        $held = $this->dispatch(new ReportContent(EntityId::generate(), ModerationKind::Rights, $number, null, [$photos[10]], 'Erika Beispiel', 'erika@example.ch', 'Dieses Foto habe ich aufgenommen.', null, hold: false));

        self::assertSame(0, $held, 'the daily budget is spent: the case is stored, nothing is hidden');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM moderation_cases'));
        self::assertSame([], $this->linkedPhotos());
    }

    public function test_keep_leaves_the_entry_offline_while_another_open_case_took_it_offline(): void
    {
        $venue = $this->publishable('Seehaus Zwei Meldungen', 'seehaus-zwei-meldungen');
        $number = $venue->number();
        $first = $this->reportCase($number, [], ModerationKind::Removal);
        self::assertTrue($this->dispatch(new TakeEntryOffline($first, self::ADMIN)));
        $this->connection->executeStatement("UPDATE venues SET status = 'published' WHERE number = ?", [$number]);
        $this->em->clear();
        $second = $this->reportCase($number, [], ModerationKind::Removal);
        self::assertTrue($this->dispatch(new TakeEntryOffline($second, self::ADMIN)));

        $this->dispatch(new DecideModerationCase($first, ModerationDecision::Keep, null, self::ADMIN));
        $this->em->clear();

        self::assertFalse($this->reload($number)->isPublished(), 'the second case still wants the entry offline');

        $this->dispatch(new DecideModerationCase($second, ModerationDecision::Keep, null, self::ADMIN));
        $this->em->clear();

        self::assertTrue($this->reload($number)->isPublished());
    }

    /**
     * @param list<string> $photoIds
     *
     * @return int the photos it hid
     */
    private function report(int $number, array $photoIds, ModerationKind $kind = ModerationKind::Rights): int
    {
        $held = $this->dispatch(new ReportContent(
            EntityId::generate(),
            $kind,
            $number,
            null,
            $photoIds,
            'Erika Beispiel',
            'erika@example.ch',
            'Dieses Foto habe ich aufgenommen.',
            '/eventlokal/'.$this->connection->fetchOne('SELECT t.slug FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.number = ?', [$number]),
        ));
        self::assertIsInt($held);
        $this->em->clear();

        return $held;
    }

    /**
     * @param list<string> $photoIds
     *
     * @return string the id of the case the report stored
     */
    private function reportCase(int $number, array $photoIds, ModerationKind $kind = ModerationKind::Rights): string
    {
        $this->report($number, $photoIds, $kind);

        return (string) $this->connection->fetchOne('SELECT id FROM moderation_cases ORDER BY created_at DESC, id DESC LIMIT 1');
    }

    /**
     * @return list<string>
     */
    private function linkedPhotos(): array
    {
        return array_map(strval(...), $this->connection->fetchFirstColumn('SELECT venue_photo_id FROM moderation_case_photos ORDER BY created_at, id'));
    }

    /**
     * @return array{listing: list<string>, merkliste: list<string>, similar: list<string>, detail: list<string>, rooms: list<string>, sitemap: list<string>}
     */
    private function seen(int $number): array
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return PublicPhotos::of($this->connection, $translator, $number);
    }

    private function grantedPhoto(int $number): string
    {
        return $this->addPhoto($number, $this->upload(), grant: new PhotoGrant(Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation))->photoId;
    }

    private function venue(string $name, string $slug, string $tier = KnownTiers::FREE): Venue
    {
        $venue = VenueFactory::createOne(['name' => $name, 'town' => DetailVenues::town('Zürich'), 'tier' => $tier]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug]);

        return $venue;
    }

    /**
     * Online, with a summary, a main building and a gallery photo, so `PublishVenue` takes it back.
     */
    private function publishable(string $name, string $slug): Venue
    {
        $venue = $this->venue($name, $slug);
        VenueTermFactory::new()->term(TermGroup::Building, 'castle')->main()->create(['venue' => $venue]);
        $this->grantedPhoto($venue->number());

        return $venue;
    }

    private function resetPurger(): void
    {
        $this->purger()->venues = [];
        $this->purger()->listings = 0;
    }
}
