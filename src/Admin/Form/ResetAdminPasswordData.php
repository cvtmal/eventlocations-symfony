<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Security\AdminPasswordHasher;
use Symfony\Component\Validator\Constraints as Assert;

final class ResetAdminPasswordData
{
    #[Assert\NotBlank]
    #[Assert\Length(min: AdminPasswordHasher::MIN_LENGTH, max: 4096)]
    public ?string $plainPassword = null;
}
