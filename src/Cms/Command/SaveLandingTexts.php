<?php

declare(strict_types=1);

namespace App\Cms\Command;

use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff's intro, SEO title and meta description of one landing page (#26), per locale. A
 * null or blank text removes the custom text, so the page shows #17's again. The audit row
 * names the changed locales and fields, never the texts (P9).
 */
final readonly class SaveLandingTexts implements AuditableCommand
{
    /**
     * @param string                                $keyPrefix     `canton.zh`, `town.<uuid>`, `building.<code>`
     * @param array<string, array<string, ?string>> $texts         locale → field (`intro`, `title`, `description`) → text
     * @param string                                $version       ContentVersion of the page's rows when the form was loaded
     * @param list<string>                          $changedFields the fields that differ from the loaded values, for the audit log
     * @param list<string>                          $locales       the locales that differ, for the audit log
     */
    public function __construct(
        public string $keyPrefix,
        public array $texts,
        public string $version,
        public array $changedFields,
        public array $locales,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('landing_page', $this->keyPrefix);
    }

    public function auditSummary(): array
    {
        return ['locales' => $this->locales, 'fields' => $this->changedFields];
    }
}
