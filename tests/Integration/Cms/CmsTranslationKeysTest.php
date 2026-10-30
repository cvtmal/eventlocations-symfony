<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Admin\ValueObject\AuditActorType;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueListRow;
use App\Cms\ValueObject\TriState;
use App\Cms\ValueObject\VenueCard;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\ChangeVenueWeddingFacts;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\RateVenue;
use App\Venues\Command\RecordVenueCreated;
use App\Venues\Command\RelocateVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\Exception\VenueRuleViolation;
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
        $commands = [
            RecordVenueCreated::class, ChangeVenueBasics::class, ChangeVenueTexts::class, RelocateVenue::class,
            ChangeVenueTerms::class, ChangeVenueWeddingFacts::class, ChangeVenueContact::class, AnswerVenueFaq::class,
            RateVenue::class, PublishVenue::class, UnpublishVenue::class,
        ];
        foreach ($commands as $command) {
            $keys[] = 'history.command.'.substr($command, strrpos($command, '\\') + 1);
        }

        foreach ($keys as $key) {
            self::assertTrue($catalogue->defines($key, 'cms+intl-icu') || $catalogue->defines($key, 'cms'), $key);
        }
    }
}
