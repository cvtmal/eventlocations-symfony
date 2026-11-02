<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * A location manager as the audit log sees one. It lives in Shared because Admin may not name the
 * Portal module, while AuditLogEntry and AuditLogMiddleware have to name the actor; doctrine.yaml
 * resolves this interface to PortalUser for the association.
 */
interface PortalActor
{
    public function id(): string;
}
