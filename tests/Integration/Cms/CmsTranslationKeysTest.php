<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Admin\ValueObject\AuditActorType;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueListRow;
use App\Cms\ValueObject\TriState;
use App\Cms\ValueObject\VenueCard;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\AddRoom;
use App\Venues\Command\AddVenueDocument;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\AssignVenueGooglePlace;
use App\Venues\Command\CancelSubscription;
use App\Venues\Command\ChangeRoom;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueCapacity;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\ChangeVenueTier;
use App\Venues\Command\CheckVenueFacts;
use App\Venues\Command\DecideModerationCase;
use App\Venues\Command\DescribeVenuePhoto;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Command\IssueVenuePreview;
use App\Venues\Command\MoveVenuePhotoToRoom;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\RecordSubscriptionPayment;
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
use App\Venues\Command\ReviseTier;
use App\Venues\Command\RevokeVenuePreview;
use App\Venues\Command\TakeEntryOffline;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Command\VoidSubscription;
use App\Venues\Exception\VenueRuleViolation;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Keys of the `cms` catalogue that are built at runtime, invisible to `debug:translation`.
 */
final class CmsTranslationKeysTest extends KernelTestCase
{
    public function test_runtime_keys_exist_in_the_catalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');
        $locales = self::getContainer()->get(EnabledLocale::class);
        self::assertInstanceOf(EnabledLocale::class, $locales);

        $keys = [
            'create.flash', 'delete.flash', 'editor.saved', 'editor.unchanged', 'error.stale', 'history.command.fallback',
            'publish.error.missing', 'publish.flash.published', 'publish.flash.unpublished',
            'publish.flash.already_published', 'publish.flash.already_unpublished', 'list.status.incomplete',
            'editor.card.capacity',
        ];
        foreach (VenueRuleViolation::all() as $violation) {
            $keys[] = $violation->translationKey();
        }
        foreach (VenueCard::cases() as $card) {
            $keys[] = 'editor.card.'.$card->value;
        }
        foreach ($locales->all() as $locale) {
            $keys[] = 'editor.locale.'.$locale;
        }
        foreach ([VenueEdit::MISSING_SUMMARY, VenueEdit::MISSING_MAIN_BUILDING, VenueEdit::MISSING_PHOTO] as $code) {
            $keys[] = 'editor.checklist.item.'.$code;
        }
        foreach ([VenueListRow::MISSING_PHOTO, VenueListRow::MISSING_INQUIRY_EMAIL, VenueListRow::MISSING_SUMMARY, VenueListRow::MISSING_MAIN_BUILDING] as $code) {
            $keys[] = 'dashboard.incomplete.missing.'.$code;
        }
        foreach (VenueStatus::cases() as $status) {
            $keys[] = 'list.status.'.$status->value;
        }
        foreach (AuditActorType::cases() as $actor) {
            $keys[] = 'history.actor.'.$actor->value;
        }
        foreach (TriState::cases() as $state) {
            $keys[] = 'tri_state.'.$state->value;
        }
        // The "Bildrechte" card names what a grant rests on (G1).
        foreach (GrantEvidence::cases() as $evidence) {
            $keys[] = 'photos.rights.evidence.'.$evidence->value;
        }
        // The staff queue of the complaints names their kind and decision (G2).
        foreach (ModerationKind::cases() as $kind) {
            $keys[] = 'moderation.kind.'.$kind->value;
        }
        foreach (ModerationDecision::cases() as $decision) {
            $keys[] = 'moderation.decision.'.$decision->value;
        }
        $commands = [
            RecordVenueCreated::class, ChangeVenueBasics::class, ChangeVenueTexts::class, RelocateVenue::class,
            ChangeVenueTerms::class, ChangeVenueEventFacts::class, ChangeVenueContact::class, ChangeVenueCapacity::class, AnswerVenueFaq::class,
            ChangeVenueTier::class, RecordSubscriptionPayment::class, CancelSubscription::class, VoidSubscription::class, ReviseTier::class, PublishVenue::class, UnpublishVenue::class,
            AddVenuePhoto::class, ReorderVenuePhotos::class, DescribeVenuePhoto::class, MoveVenuePhotoToRoom::class, RemoveVenuePhoto::class, GrantPhotoRights::class,
            AddRoom::class, ChangeRoom::class, ReorderRooms::class, RemoveRoom::class,
            AddVenueDocument::class, RetitleVenueDocument::class, ReorderVenueDocuments::class, RemoveVenueDocument::class,
            ChangeVenueGooglePlace::class, AssignVenueGooglePlace::class,
            IssueVenuePreview::class, RevokeVenuePreview::class,
            ReportContent::class, TakeEntryOffline::class, DecideModerationCase::class,
            CheckVenueFacts::class,
        ];
        foreach ($commands as $command) {
            $keys[] = 'history.command.'.substr($command, strrpos($command, '\\') + 1);
        }
        // Retired in #53: the commands are gone, but old audit rows still name them in the Verlauf.
        foreach (['RateVenue', 'AddVenueReview', 'ChangeVenueReview', 'RemoveVenueReview'] as $retired) {
            $keys[] = 'history.command.'.$retired;
        }

        foreach ($keys as $key) {
            self::assertTrue($catalogue->defines($key, 'cms+intl-icu') || $catalogue->defines($key, 'cms'), $key);
        }
    }
}
