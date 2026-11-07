<?php

declare(strict_types=1);

namespace App\Venues\Twig;

use App\Venues\RightsPolicy;
use Twig\Attribute\AsTwigFunction;

/**
 * `{{ rights_declaration() }}` prints the declaration text in force (G1) as HTML, on the pages that ask a
 * manager to agree to it: the submit step of the wizard and the Fotos tab of `/konto`. The text is the
 * staff-edited block `rights.declaration`, else the catalogue default (`Venues\RightsPolicy`); it is
 * rendered from Markdown with raw HTML escaped, so the output is safe to print.
 */
final class RightsDeclarationExtension
{
    public function __construct(
        private readonly RightsPolicy $policy,
    ) {
    }

    #[AsTwigFunction('rights_declaration', isSafe: ['html'])]
    public function declaration(): string
    {
        return $this->policy->currentHtml();
    }
}
