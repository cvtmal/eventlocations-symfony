<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Entity\ActivityType;
use App\Crm\ReadModel\TaskView;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\TaskDue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Keys of the `crm` catalogue that are built at runtime or picked by a branch, invisible to
 * `debug:translation`: the enums, the activity types, and what an exception becomes (`CrmApi`, the
 * record controllers, the bootstrap).
 */
final class CrmTranslationKeysTest extends KernelTestCase
{
    public function test_runtime_keys_exist_in_the_catalogue(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        $keys = [
            // CrmApi: StaleProperty, CompanyNotFound|ContactNotFound|ActivityNotFound, ContactEmailTaken, InvalidPropertyValue,
            // ActivityNotYours, ActivityReadOnly.
            'api.stale', 'api.not_found', 'api.email_taken', 'api.required', 'api.unknown_choice', 'api.not_yours', 'api.read_only',
            // CompanyRecordController: VenueAlreadyLinked, VenueNotFound, a refused form.
            'record.venues.flash.already_linked', 'record.venues.flash.venue_not_found', 'record.venues.flash.invalid',
            'flash.action_failed',
            // The bootstrap and the panel: BootstrapOutcome's two branches.
            'bootstrap.flash.done', 'bootstrap.flash.failures', 'panel.flash.created', 'panel.flash.already',
            // TimelineProps' private list, and the quick actions.
            'timeline.section', 'timeline.filters', 'timeline.all', 'timeline.empty', 'timeline.empty_filtered', 'timeline.more',
            'timeline.anonymised', 'timeline.composer.title', 'timeline.composer.tabs', 'timeline.composer.date', 'timeline.composer.saved',
            'timeline.task.label', 'timeline.task.placeholder', 'timeline.task.submit', 'timeline.task.due_on', 'timeline.task.assignee',
            'timeline.entry.edit', 'timeline.entry.edit_body', 'timeline.entry.save', 'timeline.entry.cancel', 'timeline.entry.delete',
            'timeline.entry.delete_title', 'timeline.entry.delete_text', 'timeline.entry.delete_confirm', 'timeline.entry.deleted',
            'timeline.close', 'timeline.reload', 'timeline.session_gone', 'timeline.page_outdated', 'timeline.error', 'timeline.by',
            'timeline.system.published', 'timeline.system.unpublished', 'timeline.system.tier_changed',
        ];
        foreach (CompanyStage::cases() as $case) {
            $keys[] = 'company.stage.'.$case->value;
        }
        foreach (CompanyOrigin::cases() as $case) {
            $keys[] = 'company.origin.'.$case->value;
        }
        foreach (CompanyProperty::cases() as $case) {
            $keys[] = 'company.property.'.$case->value;
        }
        foreach (ContactKind::cases() as $case) {
            $keys[] = 'contact.kind.'.$case->value;
        }
        foreach (ContactProperty::cases() as $case) {
            $keys[] = 'contact.property.'.$case->value;
        }
        foreach ([TaskDue::Overdue, TaskDue::Today] as $case) {
            $keys[] = 'task.due.'.$case->value;
        }
        foreach (TaskView::cases() as $case) {
            $keys[] = 'task.list.tab.'.$case->value;
            $keys[] = 'task.list.empty.'.$case->value.'.title';
            $keys[] = 'task.list.empty.'.$case->value.'.text';
        }
        foreach ([ActivityType::NOTE, ActivityType::EMAIL_LOGGED, ActivityType::CALL, ActivityType::MEETING] as $code) {
            $keys[] = 'activity.type.'.$code;
            $keys[] = 'activity.tab.'.$code;
            $keys[] = 'activity.compose.'.$code.'.placeholder';
            $keys[] = 'activity.compose.'.$code.'.submit';
        }

        foreach ($keys as $key) {
            self::assertTrue($catalogue->defines($key, 'crm+intl-icu') || $catalogue->defines($key, 'crm'), $key);
        }
    }

    public function test_the_catalogue_has_a_label_for_every_seeded_activity_type(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $catalogue = $translator->getCatalogue('de');

        foreach ($connection->fetchFirstColumn('SELECT code FROM crm_activity_types') as $code) {
            self::assertTrue($catalogue->defines('activity.type.'.$code, 'crm+intl-icu'), (string) $code);
        }
    }
}
