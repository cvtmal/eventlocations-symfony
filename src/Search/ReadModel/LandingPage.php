<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Search\ValueObject\LandingType;

/**
 * A live landing page: a canton, a town or a building type with at least
 * `%landing.min_venues%` published venues. The path holds the facet, so a landing request
 * parses `facetQuery()` before the query string and the path wins (P3).
 */
final readonly class LandingPage
{
    /** The slug shape of #8's VenueDetailController::SLUG_REQUIREMENT; a Search controller may not import it. */
    public const SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * @param string      $slug       the last path segment: `cantons.slug`, `towns.slug` or the building's catalogue slug
     * @param string|null $cantonSlug the first path segment of a town page, and a canton page's own slug
     */
    public function __construct(
        public LandingType $type,
        public string $slug,
        public int $venues,
        public ?Canton $canton = null,
        public ?string $cantonSlug = null,
        public ?string $townId = null,
        public ?string $townName = null,
        public ?string $building = null,
    ) {
    }

    /**
     * The listing parameters the path stands for, in `SearchCriteria::toQuery()` shape.
     *
     * @return array<string, string|list<string>>
     */
    public function facetQuery(): array
    {
        return match ($this->type) {
            LandingType::Canton => ['canton' => $this->canton()->value],
            LandingType::Town => ['canton' => $this->canton()->value, 'place' => (string) $this->townId, 'radius' => '0'],
            LandingType::Building => ['building' => [(string) $this->building]],
        };
    }

    /**
     * The parameters the path holds, so neither a URL nor a form repeats them.
     *
     * @return list<string>
     */
    public function consumes(): array
    {
        return array_keys($this->facetQuery());
    }

    private function canton(): Canton
    {
        return $this->canton ?? throw new \LogicException(\sprintf('A %s landing page has no canton.', $this->type->value));
    }
}
