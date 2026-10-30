<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\ValueObject\AdminRole;
use Symfony\Component\Validator\Constraints as Assert;

final class ChangeAdminRoleData
{
    public function __construct(
        #[Assert\NotNull]
        public ?AdminRole $role = null,
    ) {
    }
}
