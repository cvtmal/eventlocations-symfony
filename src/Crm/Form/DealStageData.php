<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\LostReason;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The record's stage form (N8), the no-JS path of a move. `expected` is the stage the page showed (D17-b).
 */
final class DealStageData
{
    #[Assert\NotBlank(message: 'crm.property.required')]
    public ?string $stage = null;

    #[Assert\NotBlank(message: 'crm.property.required')]
    public ?string $expected = null;

    public ?LostReason $lostReason = null;
}
