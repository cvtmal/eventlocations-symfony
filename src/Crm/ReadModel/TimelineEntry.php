<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\ActivityDate;

/**
 * One line of a record's timeline: a staff-written activity, or a system event. A system event is a linked venue's,
 * read from the audit log (company timeline only), or a deal's stage change, an activity row with a snapshot in its
 * payload (D17-d). A system entry has no body and nobody can edit it.
 */
final readonly class TimelineEntry
{
    public const string ACTIVITY = 'activity';
    public const string SYSTEM = 'system';

    public const string EVENT_PUBLISHED = 'published';
    public const string EVENT_UNPUBLISHED = 'unpublished';
    public const string EVENT_TIER_CHANGED = 'tier_changed';
    public const string EVENT_DEAL_STAGE_CHANGED = 'deal_stage_changed';
    public const string EMAIL_SENT = 'email_sent';

    /**
     * @param string                                              $typeCode      the activity type, or the event for a system entry
     * @param string|null                                         $actorId       the admin user who wrote an activity
     * @param string|null                                         $venueName     the venue of a system entry
     * @param string|null                                         $tier          the new tier of a `tier_changed` event
     * @param string|null                                         $stage         the new stage's code of a `deal_stage_changed` event, whose `title` is the deal's name
     * @param string|null                                         $inquiryId     the inquiry of an `inquiry` entry, whose `title` is the venue's name; null once purged or anonymised
     * @param string|null                                         $inquiryCouple the couple's name, read live from that inquiry
     * @param array{to: string, cc: ?string, status: string}|null $email         the send of an `email_sent` entry, whose `title` is the subject; null once anonymised
     */
    public function __construct(
        public string $kind,
        public string $id,
        public string $typeCode,
        public \DateTimeImmutable $occurredAt,
        public ?string $actorId,
        public ?string $actorName,
        public ?string $title,
        public ?string $body,
        public bool $anonymised,
        public ?int $venueNumber = null,
        public ?string $venueName = null,
        public ?string $tier = null,
        public ?string $stage = null,
        public ?string $inquiryId = null,
        public ?string $inquiryCouple = null,
        public ?array $email = null,
    ) {
    }

    public function isSystem(): bool
    {
        return self::SYSTEM === $this->kind;
    }

    /**
     * Your own entry, of a kind staff can write, not anonymised (D14). A console or system author has no id and
     * so nobody matches; an admin may not edit another admin's entry either.
     *
     * @param list<string> $composableTypes
     */
    public function canBeEditedBy(?string $viewerId, array $composableTypes): bool
    {
        return !$this->isSystem()
            && !$this->anonymised
            && null !== $viewerId
            && $viewerId === $this->actorId
            && \in_array($this->typeCode, $composableTypes, true);
    }

    /**
     * Whether an edit would leave the entry as it is (P1).
     */
    public function says(string $body, \DateTimeImmutable $occurredAt): bool
    {
        return $body === $this->body && $occurredAt->getTimestamp() === $this->occurredAt->getTimestamp();
    }

    /**
     * One entry as the timeline island reads it. `occurredAt` is local time in the composer's format, so it can
     * go straight back into the date field.
     *
     * @param list<string> $composableTypes
     *
     * @return array{kind: string, id: string, type: string, occurredAt: string, actorName: ?string, title: ?string, body: ?string, anonymised: bool, canEdit: bool, venueNumber: ?int, venueName: ?string, tier: ?string, stage: ?string, inquiryId: ?string, inquiryCouple: ?string, email: array{to: string, cc: ?string, status: string}|null}
     */
    public function toArray(?string $viewerId, array $composableTypes): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'type' => $this->typeCode,
            'occurredAt' => ActivityDate::local($this->occurredAt),
            'actorName' => $this->actorName,
            'title' => $this->title,
            'body' => $this->body,
            'anonymised' => $this->anonymised,
            'canEdit' => $this->canBeEditedBy($viewerId, $composableTypes),
            'venueNumber' => $this->venueNumber,
            'venueName' => $this->venueName,
            'tier' => $this->tier,
            'stage' => $this->stage,
            'inquiryId' => $this->inquiryId,
            'inquiryCouple' => $this->inquiryCouple,
            'email' => $this->email,
        ];
    }
}
