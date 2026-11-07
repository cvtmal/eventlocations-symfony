<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;
use App\Venues\ValueObject\ModerationKind;

/**
 * A complaint from the public form `/inhalt-melden` (G2): stores the case under `caseId` (the caller's
 * `EntityId::generate()`, so the audit row can name it) and, for a rights complaint about a known venue, links the
 * named photos that are photos of that venue and public right now. A linked photo is hidden at once (the open case
 * is the hold, `PublicPhotoPolicy`) and the venue's pages and the listings are purged after the commit. Other ids
 * are dropped without a word: the form offers only the public ones.
 *
 * `venueNumber` is a published venue the form showed; without one, `venueReference` is what the complainant typed.
 * The good-faith confirmation is the time of the submit. The mails go after the commit, from the controller.
 *
 * At most `MAX_PHOTOS` named ids are linked (the form refuses more). A named photo's file may sit in the venue's
 * gallery and in a room as two rows: every public row of the venue with the same original is linked too, so the
 * file is hidden everywhere. `hold` false (the site-wide daily budget of holds is spent, `content_report_holds`)
 * stores the case without hiding anything; the staff notice says so.
 *
 * The audit summary names the kind, the venue's number and how many photos were named, never the complainant or
 * the message (an anonymous submit is audited with the `system` actor, like every request without a login).
 */
final readonly class ReportContent implements AuditableCommand
{
    public const int MAX_PHOTOS = 10;

    /**
     * @param list<string> $photoIds
     */
    public function __construct(
        public string $caseId,
        public ModerationKind $kind,
        public ?int $venueNumber,
        public ?string $venueReference,
        public array $photoIds,
        public string $complainantName,
        public string $complainantEmail,
        public string $message,
        public ?string $sourceUrl,
        public bool $hold = true,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('moderation_case', $this->caseId);
    }

    public function auditSummary(): array
    {
        return [
            'kind' => $this->kind->value,
            'venue' => $this->venueNumber,
            'photos' => \count($this->photoIds),
        ];
    }
}
