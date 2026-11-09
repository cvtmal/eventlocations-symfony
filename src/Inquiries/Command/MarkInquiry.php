<?php

declare(strict_types=1);

namespace App\Inquiries\Command;

use App\Inquiries\ValueObject\InquiryOverride;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff mark an inquiry as spam or test, or remove the mark with null (D19, G7). The audit row holds the mark and
 * nothing of the inquiry.
 */
final readonly class MarkInquiry implements AuditableCommand
{
    public function __construct(
        public string $inquiryId,
        public ?InquiryOverride $override,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('inquiry', $this->inquiryId);
    }

    public function auditSummary(): array
    {
        return ['override' => $this->override?->value];
    }
}
