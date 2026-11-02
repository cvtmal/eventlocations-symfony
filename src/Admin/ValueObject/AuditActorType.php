<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

/**
 * Who ran an audited command, stored in `admin_audit_log.actor_type` (chk_admin_audit_log_actor_type).
 * Only `AdminUser` carries an actor id, in `actor_id`; a `PortalUser` row carries `portal_user_id`
 * instead. `System` is a web request without a logged-in user.
 */
enum AuditActorType: string
{
    case AdminUser = 'admin_user';
    case Console = 'console';
    case System = 'system';
    case PortalUser = 'portal_user';
}
