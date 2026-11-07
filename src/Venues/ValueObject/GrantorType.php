<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Who stands behind a rights grant (G1): a venue manager (`/konto`), a staff member (back office)
 * or the system (import, demo data, the migration's backfill). Stored in
 * `media_rights_grants.grantor_type` and `revoked_by_type` (`chk_media_rights_grants_grantor_type`).
 */
enum GrantorType: string
{
    case Manager = 'manager';
    case Staff = 'staff';
    case System = 'system';
}
