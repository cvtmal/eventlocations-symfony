<?php

declare(strict_types=1);

namespace App\Venues\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Adds a YouTube or Vimeo video behind the venue's last one (G15); the handler returns its id. `url` is the link as
 * pasted: the handler keeps only the provider and the video id (`VideoEmbed`), refuses a link outside the allowlist, a
 * video the venue already has and one above the plan's `videos_max` (F12). Videos are not on the cards: only the
 * venue is purged.
 */
final readonly class AddVenueVideo implements AuditableCommand
{
    public function __construct(
        public int $venueNumber,
        public string $url,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('venue', (string) $this->venueNumber);
    }

    public function auditSummary(): array
    {
        return ['url' => mb_substr($this->url, 0, 200)];
    }
}
