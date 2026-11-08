<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The code a claimant types (G6). Spaces are dropped first, as a mail client may show "123 456".
 */
final class ClaimCodeData
{
    #[Assert\NotBlank(message: 'portal.claim.code_required')]
    #[Assert\Regex(pattern: '/^[0-9]{6}$/D', message: 'portal.claim.code_format')]
    public ?string $code = null;
}
