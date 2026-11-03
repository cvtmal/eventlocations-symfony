<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Geo\ValueObject\Canton;
use App\Portal\Command\CreateOwnLocation;
use App\Venues\ValueObject\TownText;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Step 1 of the wizard (#45): what a location cannot start without. No tier or street. The canton only settles a
 * town that two cantons share, so the page shows it after such a town is refused (portal-wizard-canton).
 */
final class NewLocationData
{
    #[Assert\NotBlank(message: 'cms.venue.name.required')]
    #[Assert\Length(max: 150, maxMessage: 'cms.venue.too_long')]
    public ?string $name = null;

    #[Assert\NotNull(message: 'cms.venue.capacity.max_required')]
    #[Assert\Range(notInRangeMessage: 'cms.venue.capacity.range', min: 1, max: 50000)]
    public ?int $capacityMax = null;

    #[Assert\NotBlank(message: 'portal.onboarding.town_required')]
    public ?string $town = null;

    public ?Canton $canton = null;

    public function toCommand(string $portalUserId): CreateOwnLocation
    {
        return new CreateOwnLocation(
            $portalUserId,
            (string) $this->name,
            $this->capacityMax ?? throw new \LogicException('Validate the form before building its command.'),
            (string) $this->town,
            $this->canton?->value,
        );
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        if (null !== $this->town && mb_check_encoding($this->town, 'UTF-8') && null === TownText::parse($this->town)) {
            $context->buildViolation('cms.venue.town.format')->atPath('town')->addViolation();
        }
    }
}
