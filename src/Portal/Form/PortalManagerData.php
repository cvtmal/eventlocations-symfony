<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The managers panel's form. The names are optional here: an existing account keeps its own, and the controller
 * asks for both only when the address is new, because the CRM contact needs a last name.
 */
final class PortalManagerData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 100)]
    public ?string $firstName = null;

    #[Assert\Length(max: 100)]
    public ?string $lastName = null;
}
