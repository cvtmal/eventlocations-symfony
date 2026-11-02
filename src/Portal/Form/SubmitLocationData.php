<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The rights confirmation of "Veröffentlichen" (#45, S16). The time is stored by the handler.
 */
final class SubmitLocationData
{
    #[Assert\IsTrue(message: 'portal.onboarding.rights_required')]
    public bool $rights = false;
}
