<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Shared\Messenger\AuditableCommand;
use App\Venues\Command\AddRoom;
use App\Venues\Command\AddVenueDocument;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\Command\ChangeRoom;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueCapacity;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\DescribeVenuePhoto;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Command\IssueVenuePreview;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\RecordVenueCreated;
use App\Venues\Command\RelocateVenue;
use App\Venues\Command\RemoveRoom;
use App\Venues\Command\RemoveVenueDocument;
use App\Venues\Command\RemoveVenuePhoto;
use App\Venues\Command\ReorderRooms;
use App\Venues\Command\ReorderVenueDocuments;
use App\Venues\Command\ReorderVenuePhotos;
use App\Venues\Command\ReportContent;
use App\Venues\Command\RetitleVenueDocument;
use App\Venues\Command\RevokeVenuePreview;
use App\Venues\Command\TakeEntryOffline;
use App\Venues\Command\UnpublishVenue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every auditable venue command of #24, #25 and #28 (D9, D11, S12): the subject is the venue's number,
 * and the summary stored in the append-only audit log holds field names and short
 * vocabulary values only, never free text, contact data or a coordinate.
 */
final class VenueCommandAuditTest extends TestCase
{
    private const int NUMBER = 4711;
    private const string ADMIN = '0199a1b2-0000-7000-8000-0000000000ad';
    private const string MANAGER = '0199a1b2-0000-7000-8000-0000000000aa';
    private const string CASE = '0199a1b2-0000-7000-8000-0000000000cc';

    /** Values that must never reach a summary. */
    private const array SECRETS = [
        'GEHEIMTEXT',
        'geheim@beispiel.ch',
        '+41449998877',
        'https://geheim.example',
        'Geheime Person',
        '47.123456',
        '8.654321',
        'Seestrasse 99',
        'Geheimregion',
        'ChIJGEHEIM12345',
    ];

    /**
     * @return iterable<string, array{AuditableCommand}>
     */
    public static function commands(): iterable
    {
        $text = 'GEHEIMTEXT';
        $changed = ['name', 'summary'];

        yield 'basics' => [new ChangeVenueBasics(self::NUMBER, '1', $changed, 'Schloss Muster', 'schloss-muster', Tier::Premium, 10, 100)];
        yield 'capacity' => [new ChangeVenueCapacity(self::NUMBER, '1', $changed, 180)];
        yield 'texts' => [new ChangeVenueTexts(self::NUMBER, '1', $changed, ['de' => ['summary' => $text, 'description' => $text, 'metaDescription' => $text, 'parkingText' => $text]])];
        yield 'relocate' => [new RelocateVenue(self::NUMBER, '1', $changed, '8706 Meilen', 'zh', 'Seestrasse 99', 47.123456, 8.654321, '1.5', $text, '2.5', $text, '30.0', $text, ['de' => 'Geheimregion'], true, true)];
        yield 'terms' => [new ChangeVenueTerms(self::NUMBER, '1', $changed, ['building' => ['castle']], ['building' => 'castle'])];
        yield 'event facts' => [new ChangeVenueEventFacts(self::NUMBER, '1', $changed, PriceLevel::Medium, 80, 120, ['de' => ['ambience' => $text, 'capacityNote' => $text, 'openingHours' => $text]], ['catering' => ['buffet']])];
        yield 'contact' => [new ChangeVenueContact(self::NUMBER, '1', $changed, '+41449998877', 'geheim@beispiel.ch', 'https://geheim.example', 'Geheime Person', 'https://geheim.example/insta')];
        yield 'faq' => [new AnswerVenueFaq(self::NUMBER, '1', $changed, OwnCatering::Allowed, true)];
        yield 'google_place' => [new ChangeVenueGooglePlace(self::NUMBER, '1', ['placeId', 'ratingHidden'], 'ChIJGEHEIM12345', true)];
        yield 'google_place_assign' => [new AssignVenueGooglePlace(self::NUMBER, 'ChIJGEHEIM12345')];
        yield 'created' => [new RecordVenueCreated(self::NUMBER, 'Schloss Muster')];
        yield 'tier' => [new ChangeVenueTier(self::NUMBER, Tier::Premium)];
        yield 'publish' => [new PublishVenue(self::NUMBER)];
        yield 'unpublish' => [new UnpublishVenue(self::NUMBER)];
        yield 'delete' => [new DeleteVenue(self::NUMBER, 'Schloss Muster', 'schloss-muster')];
        yield 'add photo' => [new AddVenuePhoto(self::NUMBER, null, '/tmp/php/GEHEIMTEXT.jpg', 'jpg')];
        yield 'reorder photos' => [new ReorderVenuePhotos(self::NUMBER, null, ['0199a1b2-0000-7000-8000-000000000001'])];
        yield 'describe photo' => [new DescribeVenuePhoto(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001', '1', ['de' => $text])];
        yield 'move photo' => [new MoveVenuePhotoToRoom(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001', null)];
        yield 'remove photo' => [new RemoveVenuePhoto(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001')];
        yield 'remove photo as the revoker of its grant' => [new RemoveVenuePhoto(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001', Grantor::staff(self::ADMIN))];
        yield 'grant photo rights' => [new GrantPhotoRights(self::NUMBER, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, true, ['0199a1b2-0000-7000-8000-000000000001'])];
        yield 'grant the photo rights of the whole venue' => [new GrantPhotoRights(self::NUMBER, Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation)];
        yield 'add room' => [new AddRoom(self::NUMBER, ['de' => $text], ['de' => $text], 10, 100, 120, '4.20', 'parquet', ['banquet' => ['min' => 10, 'max' => 80]])];
        yield 'change room' => [new ChangeRoom(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001', '1', ['de' => $text], ['de' => $text], 10, 100, 120, '4.20', 'parquet', [])];
        yield 'reorder rooms' => [new ReorderRooms(self::NUMBER, ['0199a1b2-0000-7000-8000-000000000001'])];
        yield 'remove room' => [new RemoveRoom(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001')];
        yield 'add document' => [new AddVenueDocument(self::NUMBER, '/tmp/php/GEHEIMTEXT.pdf', ['de' => $text])];
        yield 'retitle document' => [new RetitleVenueDocument(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001', '1', ['de' => $text])];
        yield 'reorder documents' => [new ReorderVenueDocuments(self::NUMBER, ['0199a1b2-0000-7000-8000-000000000001'])];
        yield 'remove document' => [new RemoveVenueDocument(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001')];
        yield 'issue preview link' => [new IssueVenuePreview(self::NUMBER, 'herbst-2026', 90)];
        yield 'revoke preview link' => [new RevokeVenuePreview(self::NUMBER, '0199a1b2-0000-7000-8000-000000000001')];
    }

    #[DataProvider('commands')]
    public function test_the_subject_is_the_venue_number(AuditableCommand $command): void
    {
        self::assertSame('venue', $command->auditSubject()->type);
        self::assertSame((string) self::NUMBER, $command->auditSubject()->id);
    }

    #[DataProvider('commands')]
    public function test_the_summary_holds_no_free_text_contact_data_or_coordinate(AuditableCommand $command): void
    {
        $summary = json_encode($command->auditSummary(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $summary);
        }
    }

    public function test_the_rights_commands_name_the_choice_and_leave_the_people_to_the_audit_row(): void
    {
        $photo = '0199a1b2-0000-7000-8000-000000000001';

        // The revoker is the audit row's actor; the summary holds the photo only.
        self::assertSame(['photo_id' => $photo], new RemoveVenuePhoto(self::NUMBER, $photo, Grantor::staff(self::ADMIN))->auditSummary());
        self::assertSame(['photo_id' => $photo], new RemoveVenuePhoto(self::NUMBER, $photo)->auditSummary());
        self::assertSame(
            ['grantor' => 'manager', 'evidence' => 'declaration', 'derivatives' => true, 'photos' => 1],
            new GrantPhotoRights(self::NUMBER, Grantor::manager(self::MANAGER), GrantEvidence::Declaration, true, [$photo])->auditSummary(),
            'the number of photos, never their ids',
        );
        self::assertSame(
            ['grantor' => 'staff', 'evidence' => 'staff_attestation', 'derivatives' => false, 'photos' => 'all'],
            new GrantPhotoRights(self::NUMBER, Grantor::staff(self::ADMIN), GrantEvidence::StaffAttestation)->auditSummary(),
        );
    }

    /**
     * The complaint commands of G2: their subject is the case, whatever the venue.
     *
     * @return iterable<string, array{AuditableCommand}>
     */
    public static function moderationCommands(): iterable
    {
        yield 'report content about a venue' => [new ReportContent(self::CASE, ModerationKind::Rights, self::NUMBER, null, ['0199a1b2-0000-7000-8000-000000000001'], 'Geheime Person', 'geheim@beispiel.ch', 'GEHEIMTEXT', '/hochzeitslocation/geheim')];
        yield 'report content without a venue' => [new ReportContent(self::CASE, ModerationKind::Correction, null, 'Geheimregion', [], 'Geheime Person', 'geheim@beispiel.ch', 'GEHEIMTEXT', null)];
        yield 'take the entry offline' => [new TakeEntryOffline(self::CASE, self::ADMIN)];
        yield 'decide' => [new DecideModerationCase(self::CASE, ModerationDecision::Remove, 'GEHEIMTEXT', self::ADMIN)];
    }

    #[DataProvider('moderationCommands')]
    public function test_the_subject_of_a_complaint_command_is_the_case(AuditableCommand $command): void
    {
        self::assertSame('moderation_case', $command->auditSubject()->type);
        self::assertSame(self::CASE, $command->auditSubject()->id);
    }

    #[DataProvider('moderationCommands')]
    public function test_a_complaint_summary_holds_no_free_text_or_contact_data(AuditableCommand $command): void
    {
        $summary = json_encode($command->auditSummary(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        foreach ([...self::SECRETS, '/hochzeitslocation/geheim'] as $secret) {
            self::assertStringNotContainsString($secret, $summary);
        }
    }

    public function test_the_complaint_commands_name_the_kind_the_venue_and_the_decision(): void
    {
        self::assertSame(
            ['kind' => 'rights', 'venue' => self::NUMBER, 'photos' => 2],
            new ReportContent(self::CASE, ModerationKind::Rights, self::NUMBER, null, ['a', 'b'], 'X', 'x@example.ch', 'Text', null)->auditSummary(),
            'the number of named photos, never their ids',
        );
        self::assertSame(['kind' => 'correction', 'venue' => null, 'photos' => 0], new ReportContent(self::CASE, ModerationKind::Correction, null, 'Ort', [], 'X', 'x@example.ch', 'Text', null)->auditSummary());
        self::assertSame(['entry' => 'offline'], new TakeEntryOffline(self::CASE, self::ADMIN)->auditSummary());
        self::assertSame(['decision' => 'keep'], new DecideModerationCase(self::CASE, ModerationDecision::Keep, 'Notiz', self::ADMIN)->auditSummary(), 'the note stays on the case');
    }

    public function test_a_form_command_lists_its_changed_fields(): void
    {
        self::assertSame(['changed' => ['phone']], new ChangeVenueContact(self::NUMBER, '1', ['phone'], '+41449998877', null, null, null, null)->auditSummary());
    }
}
