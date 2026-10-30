<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Security\AdminPasswordHasher;
use App\Admin\ValueObject\AdminRole;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateAdminUserData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $displayName = null;

    #[Assert\NotNull]
    public ?AdminRole $role = AdminRole::Editor;

    #[Assert\NotBlank]
    #[Assert\Length(min: AdminPasswordHasher::MIN_LENGTH, max: 4096)]
    public ?string $plainPassword = null;
}
