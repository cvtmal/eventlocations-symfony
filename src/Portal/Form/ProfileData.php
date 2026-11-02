<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class ProfileData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public ?string $firstName = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public ?string $lastName = null,
        #[Assert\Length(max: 40)]
        public ?string $phone = null,
    ) {
    }
}
