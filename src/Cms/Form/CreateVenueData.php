<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\CreateVenue;
use App\Venues\SlugAllocator;
use App\Venues\ValueObject\Tier;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * "Location erfassen" (CMS-3): the three facts a venue row cannot do without; the editor does the rest.
 */
final class CreateVenueData
{
    #[Assert\NotBlank(message: 'cms.venue.name.required')]
    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $name = null;

    #[Assert\NotNull(message: 'cms.venue.tier.required')]
    public ?Tier $tier = Tier::Basic;

    #[Assert\NotNull(message: 'cms.venue.capacity.max_required')]
    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMax = null;

    public function toCommand(): CreateVenue
    {
        return new CreateVenue(
            (string) $this->name,
            $this->tier ?? throw new \LogicException('Validate the form before building its command.'),
            $this->capacityMax ?? throw new \LogicException('Validate the form before building its command.'),
        );
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->name && mb_check_encoding($this->name, 'UTF-8') && '' === SlugAllocator::base($this->name)) {
            $context->buildViolation('cms.venue.name.no_slug')->atPath('name')->addViolation();
        }
    }
}
