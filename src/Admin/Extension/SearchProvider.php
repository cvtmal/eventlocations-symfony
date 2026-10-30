<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Feeds the global ⌘K search. The registry and the endpoint are #23's.
 */
#[AutoconfigureTag('admin.search_provider')]
interface SearchProvider
{
    /**
     * @return list<SearchResult>
     */
    public function search(string $q, int $limit): array;
}
