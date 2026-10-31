<?php

declare(strict_types=1);

namespace App\Cms\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff's Markdown of one declared text block (#26, `listing.intro`, `footer.note`), per
 * locale. A null or blank body removes the row, so the page shows the block's fallback or
 * nothing. The audit row names the changed locales, never the text (P9).
 */
final readonly class SaveTextBlock implements AuditableCommand
{
    /**
     * @param array<string, ?string> $bodies  locale → Markdown
     * @param string                 $version ContentVersion of the block's rows when the form was loaded
     * @param list<string>           $locales the locales that differ, for the audit log
     */
    public function __construct(
        public string $key,
        public array $bodies,
        public string $version,
        public array $locales,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('content_block', $this->key);
    }

    public function auditSummary(): array
    {
        return ['locales' => $this->locales, 'fields' => ['body']];
    }
}
