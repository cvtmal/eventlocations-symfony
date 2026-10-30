<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The thirteen taxonomy groups. The first seven are the groups of the venue filters (DP-7,
 * FI-4), in filter order; the six added by #31 are owner facts (S14, DP-17) with no filter
 * yet (#33).
 *
 * The value is stored in `taxonomy_terms.term_group` / `venue_terms.term_group`
 * (chk_taxonomy_terms_term_group); the Search\ReadModel\SearchCriteria codec maps the groups
 * it filters on to a query parameter (S4), so only those have one. The terms of each group
 * are rows of `taxonomy_terms`; labels come from the `venues` catalogue
 * (`term_group.<value>`, the term itself `term.<group>.<code>`).
 */
enum TermGroup: string implements TranslatableInterface
{
    case Setting = 'setting';
    case Building = 'building';
    case SuitableFor = 'suitable_for';
    case Infrastructure = 'infrastructure';
    case Furniture = 'furniture';
    case Technology = 'technology';
    case Surroundings = 'surroundings';
    case Ceremony = 'ceremony';
    case Amenity = 'amenity';
    case Dining = 'dining';
    case Vendor = 'vendor';
    case Usage = 'usage';
    case Language = 'language';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('term_group.'.$this->value, [], 'venues', $locale);
    }
}
