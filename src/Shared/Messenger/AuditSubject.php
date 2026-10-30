<?php

declare(strict_types=1);

namespace App\Shared\Messenger;

/**
 * What a command changed: the record's type (`admin_user`, `venue`, `deal`) and its id.
 * Maps to admin_audit_log.subject_type (VARCHAR(50)) and subject_id (VARCHAR(64)).
 */
final readonly class AuditSubject
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,49}$/', $type)) {
            throw new \InvalidArgumentException(\sprintf('Audit subject type "%s" is not snake_case of at most 50 characters.', $type));
        }

        if ('' === $id || \strlen($id) > 64) {
            throw new \InvalidArgumentException(\sprintf('Audit subject id "%s" must have 1 to 64 characters.', $id));
        }
    }
}
