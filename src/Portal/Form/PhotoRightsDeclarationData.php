<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The declaration form of the Fotos tab (G1): the manager agrees to the rights declaration for the photos that have
 * no grant yet. `derivatives` is the optional second choice, recorded with each grant.
 */
final class PhotoRightsDeclarationData
{
    #[Assert\IsTrue(message: 'portal.rights.declaration_required')]
    public bool $declaration = false;

    public bool $derivatives = false;
}
