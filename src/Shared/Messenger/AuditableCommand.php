<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

/**
 * A command that leaves a row in the back office's audit log (S12, ADM-3). It lives in Shared so
 * a public module can implement it without seeing Admin; Admin's middleware on `command.bus`
 * writes the row after the handler succeeded, inside the same transaction.
 *
 * The summary is stored and shown to admins: never put a password, a TOTP secret or a token in it.
 */
interface AuditableCommand
{
    public function auditSubject(): AuditSubject;

    /**
     * @return array<string, scalar|list<scalar>|null>
     */
    public function auditSummary(): array;
}
