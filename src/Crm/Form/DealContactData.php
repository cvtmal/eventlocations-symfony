<?php

declare(strict_types=1);

namespace App\Crm\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The form that puts a contact of the company on a deal.
 */
final class DealContactData
{
    #[Assert\NotBlank(message: 'crm.property.required')]
    public ?string $contact = null;
}
