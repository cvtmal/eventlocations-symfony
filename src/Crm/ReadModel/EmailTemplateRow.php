<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

/**
 * A mail template (CRM-10) with its raw merge fields.
 */
final readonly class EmailTemplateRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $subject,
        public string $bodyMarkdown,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
