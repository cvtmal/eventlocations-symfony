<?php

declare(strict_types=1);

namespace App\Admin\ValueObject;

use App\Admin\Extension\SearchResult;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One provider's results in the global search.
 */
final readonly class SearchGroup
{
    /**
     * @param list<SearchResult> $results
     */
    public function __construct(
        public TranslatableInterface $label,
        public array $results,
    ) {
    }
}
