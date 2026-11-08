<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\ReadModel\LandingPage;
use App\Search\ValueObject\LandingType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The catalogue text of a landing page (#17, F8): its h1, its intro sentence (the default the
 * back office's intro block replaces), its link labels and the parameters Seo's titles take.
 * The one place that knows the `landing.*` keys of the `search` catalogue, so the listing
 * template, Seo's ListingMeta and the back office's catalogue cannot drift apart.
 */
final readonly class LandingCopy
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function heading(LandingPage $page): string
    {
        return match ($page->type) {
            LandingType::Building => $this->trans('landing.building.'.$page->building.'.heading'),
            LandingType::Event => null === $page->canton
                ? $this->translator->trans('landing.event.heading', $this->parameters($page), 'search')
                : $this->translator->trans('landing.event.heading_canton', $this->parameters($page), 'search'),
            default => $this->trans('landing.'.$page->type->value.'.heading', $this->parameters($page)),
        };
    }

    public function intro(LandingPage $page): string
    {
        $parameters = $this->parameters($page) + ['count' => $page->venues];

        return match ($page->type) {
            LandingType::Building => $this->trans('landing.building.'.$page->building.'.intro', $parameters),
            LandingType::Event => null === $page->canton
                ? $this->trans('landing.event.'.$page->event.'.intro', $parameters)
                : $this->translator->trans('landing.event.intro_canton', $parameters, 'search'),
            default => $this->trans('landing.'.$page->type->value.'.intro', $parameters),
        };
    }

    /**
     * The label of a link to the page in "Andere suchten auch nach" (LP-5).
     */
    public function related(LandingPage $page): string
    {
        return match ($page->type) {
            LandingType::Canton => $this->translator->trans('related.canton', ['canton' => $this->canton($page)], 'search'),
            LandingType::Town => $this->translator->trans('related.town', ['town' => (string) $page->townName], 'search'),
            LandingType::Building => $this->heading($page),
            LandingType::Event => null === $page->canton ? $this->heading($page) : $this->translator->trans('landing.event.related_canton', $this->parameters($page), 'search'),
        };
    }

    /**
     * The page's own item in the breadcrumb.
     */
    public function breadcrumb(LandingPage $page): string
    {
        return match ($page->type) {
            LandingType::Canton => $this->translator->trans('breadcrumb.canton', ['canton' => $this->canton($page), 'canton_code' => $page->canton?->value], 'seo'),
            LandingType::Town => (string) $page->townName,
            LandingType::Building => $this->heading($page),
            LandingType::Event => $this->eventName($page),
        };
    }

    /**
     * The placeholders of the page's headings and titles: `canton`, `canton_code`, `town`,
     * `event` (the event type's plural name) and `heading` (the h1 of a building page).
     *
     * @return array<string, string>
     */
    public function parameters(LandingPage $page): array
    {
        return match ($page->type) {
            LandingType::Canton => ['canton' => $this->canton($page), 'canton_code' => (string) $page->canton?->value],
            LandingType::Town => ['town' => (string) $page->townName],
            LandingType::Building => ['heading' => $this->trans('landing.building.'.$page->building.'.heading')],
            LandingType::Event => ['event' => $this->eventName($page), 'canton' => $this->canton($page), 'canton_code' => (string) $page->canton?->value],
        };
    }

    private function eventName(LandingPage $page): string
    {
        return $this->trans('landing.event.'.$page->event.'.name');
    }

    private function canton(LandingPage $page): string
    {
        return (string) $page->canton?->trans($this->translator);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'search');
    }
}
