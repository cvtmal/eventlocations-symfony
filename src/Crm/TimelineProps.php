<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\Extension\TimelineActivityType;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Crm\ReadModel\OwnerOption;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The props of the `timeline` island (D12): the first page of the record's timeline (P6, no statement beyond
 * `TimelineQuery::page()`), the tabs from the registry and the strings, translated once here so a company and a
 * contact pass the same set. `_timeline.html.twig` renders the first page from the same array as its fallback.
 */
final readonly class TimelineProps
{
    /** The composer's tab that is not an activity type: it creates a task. */
    public const string TASK_TAB = 'task';

    /**
     * The codes behind `deal.stage.<code>`, for the sentence of a stage change. A const, because the props cost no
     * statement beyond `page()`; `CrmTranslationKeysTest` holds it equal to the rows.
     *
     * @var list<string>
     */
    public const array DEAL_STAGES = ['new', 'contacted', 'offer', 'negotiation', 'won', 'lost'];

    /** Island prop => key in `crm`. */
    private const array LABELS = [
        'section' => 'timeline.section',
        'filters' => 'timeline.filters',
        'all' => 'timeline.all',
        'empty' => 'timeline.empty',
        'emptyFiltered' => 'timeline.empty_filtered',
        'more' => 'timeline.more',
        'anonymised' => 'timeline.anonymised',
        'composer' => 'timeline.composer.title',
        'composerTabs' => 'timeline.composer.tabs',
        'date' => 'timeline.composer.date',
        'saved' => 'timeline.composer.saved',
        'dueOn' => 'timeline.task.due_on',
        'assignee' => 'timeline.task.assignee',
        'edit' => 'timeline.entry.edit',
        'editBody' => 'timeline.entry.edit_body',
        'save' => 'timeline.entry.save',
        'cancel' => 'timeline.entry.cancel',
        'delete' => 'timeline.entry.delete',
        'deleteTitle' => 'timeline.entry.delete_title',
        'deleteText' => 'timeline.entry.delete_text',
        'deleteConfirm' => 'timeline.entry.delete_confirm',
        'deleted' => 'timeline.entry.deleted',
        'close' => 'timeline.close',
        'reload' => 'timeline.reload',
        'sessionGone' => 'timeline.session_gone',
        'error' => 'timeline.error',
        'notFound' => 'api.not_found',
        'forbidden' => 'timeline.page_outdated',
        'stale' => 'timeline.error',
    ];

    public function __construct(
        private TimelineQuery $timeline,
        private TimelineActivityTypeRegistry $types,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param list<OwnerOption> $assignees who a new task can go to
     *
     * @return array<string, mixed>
     */
    public function of(RecordKind $kind, string $id, string $viewerId, array $assignees): array
    {
        return [
            'kind' => $kind->value,
            'recordId' => $id,
            'page' => $this->timeline->page($kind, $id, null, null)->toArray($viewerId, $this->types->composableCodes()),
            // Every visible type is a filter tab, so each needs `activity.tab.<code>`, composable or not.
            'types' => array_map(fn (TimelineActivityType $type): array => [
                'code' => $type->code(),
                'label' => $type->label()->trans($this->translator),
                'tab' => $this->crm('activity.tab.'.$type->code()),
            ], $this->types->visible()),
            'composer' => $this->composerTabs(),
            'assignees' => array_map(static fn (OwnerOption $owner): array => ['id' => $owner->id, 'name' => $owner->name], $assignees),
            'viewerId' => $viewerId,
            'urls' => [
                'activities' => $this->urls->generate('admin_api_crm_activity_log'),
                'timeline' => $this->urls->generate('admin_api_crm_'.$kind->value.'_timeline', ['id' => $id]),
                'tasks' => $this->urls->generate('admin_api_crm_tasks'),
            ],
            'labels' => $this->labels(),
            'locale' => $this->translator->getLocale(),
        ];
    }

    /**
     * @return list<array{code: string, label: string, placeholder: string, submit: string}>
     */
    private function composerTabs(): array
    {
        $tabs = array_map(fn (TimelineActivityType $type): array => [
            'code' => $type->code(),
            'label' => $type->label()->trans($this->translator),
            'placeholder' => $this->crm('activity.compose.'.$type->code().'.placeholder'),
            'submit' => $this->crm('activity.compose.'.$type->code().'.submit'),
        ], $this->types->composable());

        $tabs[] = [
            'code' => self::TASK_TAB,
            'label' => $this->crm('timeline.task.label'),
            'placeholder' => $this->crm('timeline.task.placeholder'),
            'submit' => $this->crm('timeline.task.submit'),
        ];

        return $tabs;
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(): array
    {
        $tiers = [];
        foreach (Tier::cases() as $tier) {
            $tiers[$tier->value] = $tier->trans($this->translator);
        }

        $stages = [];
        foreach (self::DEAL_STAGES as $code) {
            $stages[$code] = $this->crm('deal.stage.'.$code);
        }

        return array_map($this->crm(...), self::LABELS) + [
            'by' => $this->crm('timeline.by', ['type' => '__type__', 'actor' => '__actor__']),
            'system' => [
                TimelineEntry::EVENT_PUBLISHED => $this->crm('timeline.system.published', ['venue' => '__venue__']),
                TimelineEntry::EVENT_UNPUBLISHED => $this->crm('timeline.system.unpublished', ['venue' => '__venue__']),
                TimelineEntry::EVENT_TIER_CHANGED => $this->crm('timeline.system.tier_changed', ['venue' => '__venue__', 'tier' => '__tier__']),
                TimelineEntry::EVENT_DEAL_STAGE_CHANGED => $this->crm('timeline.system.deal_stage_changed', ['actor' => '__actor__', 'deal' => '__deal__', 'stage' => '__stage__']),
            ],
            'dealStageChangedAnonymous' => $this->crm('timeline.system.deal_stage_changed_anonymous', ['deal' => '__deal__', 'stage' => '__stage__']),
            'tiers' => $tiers,
            'stages' => $stages,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function crm(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'crm');
    }
}
