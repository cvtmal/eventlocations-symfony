<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Cms\Form\CarriesLoadedAt;
use App\Cms\Form\VenueCardData;
use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueCapacity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The manager's card "Maximale Gästezahl" (POR-8): the maximum alone, the minimum stays with staff.
 */
final class VenueCapacityData implements VenueCardData
{
    use CarriesLoadedAt;

    #[Assert\NotNull(message: 'cms.venue.capacity.max_required')]
    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMax = null;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->capacityMax = $edit->capacityMax;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueCapacity
    {
        return new ChangeVenueCapacity(
            $venueNumber,
            $this->token(),
            $changedFields,
            $this->capacityMax ?? throw new \LogicException('Validate the form before building its command.'),
        );
    }
}
