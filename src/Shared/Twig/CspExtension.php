<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Security\CspNonce;
use Twig\Attribute\AsTwigFunction;

/**
 * `csp_nonce()` for a tag on a `no-store` page. A cached public page must not call it: the
 * stored value would serve every visitor (R1); inline code there uses a hash.
 */
final class CspExtension
{
    public function __construct(
        private readonly CspNonce $nonce,
    ) {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->nonce->value();
    }
}
