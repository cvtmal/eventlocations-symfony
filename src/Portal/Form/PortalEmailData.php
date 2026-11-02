<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class PortalEmailData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
