<?php

declare(strict_types=1);

namespace App\Crm\Command;

use App\Crm\ValueObject\InquiryStatus;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Messenger\AuditSubject;

/**
 * Staff move an inquiry to another status in the inbox (CRM-9). The audit row holds the status code and nothing of the
 * inquiry.
 */
final readonly class ChangeInquiryStatus implements AuditableCommand
{
    public function __construct(
        public string $inquiryId,
        public InquiryStatus $status,
    ) {
    }

    public function auditSubject(): AuditSubject
    {
        return new AuditSubject('inquiry', $this->inquiryId);
    }

    public function auditSummary(): array
    {
        return ['status' => $this->status->value];
    }
}
