<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class TotpEnrolmentData
{
    #[Assert\NotBlank]
    public ?string $code = null;
}
