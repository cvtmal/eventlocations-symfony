<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Portal\Security\PortalPasswordHasher;
use Symfony\Component\Validator\Constraints as Assert;

final class PortalPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: PortalPasswordHasher::MIN_LENGTH, max: 255)]
    public ?string $plainPassword = null;
}
