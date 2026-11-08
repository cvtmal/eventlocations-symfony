<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\VenueEdit;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Merkmale (D16): setting, building, infrastructure, furniture and surroundings, with the main building and setting
 * (event types and technology are the Veranstaltung card's since F11). A main term must be among the
 * selected ones (`ContentReader`'s rule; `VenueTermSet` treats a violation as a programming error).
 */
final class VenueTermsData implements VenueCardData
{
    use CarriesLoadedAt;

    /** The groups this card owns, the same as `ChangeVenueTermsHandler::GROUPS` (pinned by a test). */
    public const array GROUPS = [
        TermGroup::Setting,
        TermGroup::Building,
        TermGroup::Infrastructure,
        TermGroup::Furniture,
        TermGroup::Surroundings,
    ];

    /** @var array<string, list<string>> TermGroup value → selected codes */
    public array $terms = [];

    public ?string $mainBuilding = null;

    public ?string $mainSetting = null;

    public static function from(VenueEdit $edit): static
    {
        $data = new self();
        $data->loadedAt = $edit->loadedAt;
        foreach (self::GROUPS as $group) {
            $data->terms[$group->value] = $edit->terms[$group->value] ?? [];
        }
        $data->mainBuilding = $edit->mainTerms[TermGroup::Building->value] ?? null;
        $data->mainSetting = $edit->mainTerms[TermGroup::Setting->value] ?? null;

        return $data;
    }

    public function toCommand(int $venueNumber, VenueEdit $edit, array $changedFields): ChangeVenueTerms
    {
        return new ChangeVenueTerms($venueNumber, $this->token(), $changedFields, $this->terms, [
            TermGroup::Building->value => $this->mainBuilding,
            TermGroup::Setting->value => $this->mainSetting,
        ]);
    }

    #[Assert\Callback]
    public function validateRules(ExecutionContextInterface $context): void
    {
        $mains = ['mainBuilding' => [TermGroup::Building, $this->mainBuilding], 'mainSetting' => [TermGroup::Setting, $this->mainSetting]];
        foreach ($mains as $path => [$group, $main]) {
            if (null !== $main && !\in_array($main, $this->terms[$group->value] ?? [], true)) {
                $context->buildViolation('cms.venue.terms.main_not_selected')->atPath($path)->addViolation();
            }
        }
    }
}
