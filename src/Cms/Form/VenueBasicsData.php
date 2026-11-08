<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Controller\VenueDetailController;
use App\Venues\SlugAllocator;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Stammdaten (D15, D17): name, slug of the default locale, capacity. The tier is not edited
 * here: it follows the paid terms (G9), recorded in the CRM.
 */
final class VenueBasicsData implements VenueCardData
{
    use CarriesLoadedAt;

    #[Assert\NotBlank(message: 'cms.venue.name.required')]
    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $name = null;

    #[Assert\Length(max: 160, maxMessage: 'cms.venue.too_long')]
    #[Assert\Regex(pattern: '/\A'.VenueDetailController::SLUG_REQUIREMENT.'\z/', message: 'cms.venue.slug.format', normalizer: 'mb_scrub')]
    public ?string $slug = null;

    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMin = null;

    #[Assert\NotNull(message: 'cms.venue.capacity.max_required')]
    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMax = null;

    /** The slug as loaded, null when the venue has no default-locale row (then there is nothing to change). */
    private ?string $loadedSlug = null;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        $data->name = $edit->header->name;
        $data->slug = $edit->header->slug;
        $data->loadedSlug = $edit->header->slug;
        $data->capacityMin = $edit->capacityMin;
        $data->capacityMax = $edit->capacityMax;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueBasics
    {
        $slug = null !== $this->slug && $this->slug !== $edit->header->slug ? $this->slug : null;

        return new ChangeVenueBasics(
            $venueNumber,
            $this->token(),
            $changedFields,
            (string) $this->name,
            null === $edit->header->slug ? null : $slug,
            $this->capacityMin,
            $this->capacityMax ?? throw new \LogicException('Validate the form before building its command.'),
        );
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->name && mb_check_encoding($this->name, 'UTF-8') && '' === SlugAllocator::base($this->name)) {
            $context->buildViolation('cms.venue.name.no_slug')->atPath('name')->addViolation();
        }
        if (null !== $this->loadedSlug && null === $this->slug) {
            $context->buildViolation('cms.venue.slug.required')->atPath('slug')->addViolation();
        }
        if (null !== $this->capacityMin && null !== $this->capacityMax && $this->capacityMin > $this->capacityMax) {
            $context->buildViolation('cms.venue.capacity.max_below_min')->atPath('capacityMax')->addViolation();
        }
    }
}
