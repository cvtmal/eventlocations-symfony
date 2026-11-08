<?php

declare(strict_types=1);

namespace App\Portal\ValueObject;

/**
 * What a code did (G6, `VerifyClaim`).
 */
enum ClaimOutcome: string
{
    /** Wrong code, attempts left. */
    case WrongCode = 'wrong_code';
    /** Expired, used, too many wrong codes or unknown: a new code is needed. */
    case Dead = 'dead';
    /** Right code, but the address's account is deactivated. */
    case Deactivated = 'deactivated';
    /** Right code on the website's domain, nobody else on the venue: the claimant manages it now. */
    case Granted = 'granted';
    /** Right code, but a generic or foreign address or a competing claim: staff decide (#43's queue). */
    case Queued = 'queued';
    /** Right code by someone who already manages the venue. */
    case AlreadyManager = 'already_manager';
}
