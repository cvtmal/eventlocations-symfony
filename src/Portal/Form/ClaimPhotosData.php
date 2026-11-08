<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The claim's photo step (G6): the candidates the claimant takes over, and the declaration (G1) that grants them.
 * Without a ticked photo the declaration is not needed.
 */
final class ClaimPhotosData
{
    /** @var list<string> */
    public array $photos = [];

    public bool $declaration = false;

    public bool $derivatives = false;

    #[Assert\Callback]
    public function validateDeclaration(ExecutionContextInterface $context): void
    {
        if ([] !== $this->photos && !$this->declaration) {
            $context->buildViolation('portal.rights.declaration_required')->atPath('declaration')->addViolation();
        }
    }
}
