<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;

/**
 * One row of the detail page's fact lists (DP-7): a taxonomy group and the venue's terms in it,
 * in `taxonomy_terms.position` order. Never empty.
 */
final readonly class DetailFactGroup
{
    /**
     * @param list<Term> $terms
     */
    public function __construct(
        public TermGroup $group,
        public array $terms,
    ) {
    }
}
