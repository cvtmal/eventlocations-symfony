<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rights confirmation of "Veröffentlichen" (#45, S16). The time is stored by the handler. The confirmation is
 * also the declaration for the photos (G1); `derivatives` is the optional second choice, recorded with each grant.
 */
final class SubmitLocationData
{
    #[Assert\IsTrue(message: 'portal.onboarding.rights_required')]
    public bool $rights = false;

    public bool $derivatives = false;
}
