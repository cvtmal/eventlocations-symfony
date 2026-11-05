<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Shared\Validator\CleanText;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Eintrag zuordnen" on a Location (CRM-1): the venue id the combobox submits.
 */
final class LinkVenueData
{
    #[Assert\NotNull(message: 'crm.link_venue.required')]
    #[CleanText]
    #[Assert\Uuid(message: 'crm.link_venue.required', normalizer: 'mb_scrub')]
    public ?string $venue = null;
}
