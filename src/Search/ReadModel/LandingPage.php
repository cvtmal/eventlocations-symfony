<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Geo\ValueObject\Canton;
use App\Search\ValueObject\LandingType;

/**
 * A live landing page: a canton, a town, a building type or an event type (F8; alone or in a
 * canton) with at least `%landing.min_venues%` published venues. The path holds the facet, so a
 * landing request parses `facetQuery()` before the query string and the path wins (P3).
 *
 * `indexable` is the G12 hook: whether the page passes LandingQualityGate. A live page that fails
 * it still answers 200, but is `noindex` and stays out of the sitemap and llms.txt.
 */
final readonly class LandingPage
{
    /** The slug shape of #8's VenueDetailController::SLUG_REQUIREMENT; a Search controller may not import it. */
    public const SLUG_REQUIREMENT = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /**
     * @param string      $slug       the last path segment: `cantons.slug`, `towns.slug`, the building's or the event type's catalogue slug
     * @param string|null $cantonSlug the first path segment of a town or event x canton page, and a canton page's own slug
     * @param string|null $event      the `suitable_for` code of an event page
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
        public ?string $event = null,
        public bool $indexable = true,
    ) {
    }

    public function withIndexable(bool $indexable): self
    {
        return new self($this->type, $this->slug, $this->venues, $this->canton, $this->cantonSlug, $this->townId, $this->townName, $this->building, $this->event, $indexable);
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
            LandingType::Event => (null === $this->canton ? [] : ['canton' => $this->canton->value]) + ['event' => [(string) $this->event]],
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

    /**
     * The page's identity in `content_blocks` keys (#26): `canton.<code>`, `town.<towns.id>`,
     * `building.<code>`, `event.<code>` or `event.<code>.<canton>`. Never the slug, so a renamed
     * town, building or event slug keeps its texts.
     */
    public function keyPrefix(): string
    {
        return match ($this->type) {
            LandingType::Canton => 'canton.'.$this->canton()->value,
            LandingType::Town => 'town.'.$this->townId,
            LandingType::Building => 'building.'.$this->building,
            LandingType::Event => 'event.'.$this->event.(null === $this->canton ? '' : '.'.$this->canton->value),
        };
    }

    /**
     * The `content_blocks` key of one of the page's editable texts (`intro`, `title`, `description`).
     */
    public function blockKey(string $field): string
    {
        return 'landing.'.$this->keyPrefix().'.'.$field;
    }

    private function canton(): Canton
    {
        return $this->canton ?? throw new \LogicException(\sprintf('A %s landing page has no canton.', $this->type->value));
    }
}
