<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The ten taxonomy groups. The first seven are the groups of the venue filters (DP-7, FI-4),
 * in filter order; amenity, catering and language are the event facts of the detail page's
 * "Angaben zur Location" (event-vocabulary, F3), and have a filter only where Search says so.
 *
 * The value is stored in `taxonomy_terms.term_group` / `venue_terms.term_group`
 * (chk_taxonomy_terms_term_group). The case order is the CHECK's order and the key order of
 * `terms` in docs/content-format.schema.json. The Search\ReadModel\SearchCriteria codec maps
 * the groups it filters on to a query parameter (S4), so only those have one. The terms of
 * each group are rows of `taxonomy_terms`; labels come from the `venues` catalogue
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
    case Amenity = 'amenity';
    case Catering = 'catering';
    case Language = 'language';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('term_group.'.$this->value, [], 'venues', $locale);
    }
}
