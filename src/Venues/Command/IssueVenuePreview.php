<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Issues a private preview link of an entry (G5) for one campaign, valid for `lifetimeDays` days
 * (one of `LIFETIMES`) from now. Answers the new link's id; `Venues\PreviewToken::forId()` turns it
 * into the link. Any status: a draft and a published entry can both be previewed. The campaign code
 * must pass `Shared\Funnel\CampaignCode::isValid()` (a form checks it first; the handler refuses
 * anything else with an `\InvalidArgumentException`, like an unknown lifetime).
 */
final readonly class IssueVenuePreview implements AuditableCommand
{
    /** The lifetimes staff can choose, in days. */
    public const array LIFETIMES = [30, 90, 180];

    public const int DEFAULT_LIFETIME = 90;

    public function __construct(
        public int $venueNumber,
        public ?string $campaignCode,
        public int $lifetimeDays = self::DEFAULT_LIFETIME,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    /**
     * The campaign code is a plain code (no personal data), null for a link sent by e-mail; the link's token and short
     * code never go here.
     */
    public function auditSummary(): array
    {
        return ['campaign' => $this->campaignCode, 'days' => $this->lifetimeDays];
    }
}
