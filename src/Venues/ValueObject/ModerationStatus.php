<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A complaint is open until staff decide it (G2). An open case that names photos holds them
 * (`Venues\PublicPhotoPolicy`). Stored in `moderation_cases.status` (`chk_moderation_cases_status`).
 */
enum ModerationStatus: string
{
    case Open = 'open';
    case Decided = 'decided';
}
