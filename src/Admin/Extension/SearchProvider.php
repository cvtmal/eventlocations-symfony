<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Feeds the global ⌘K search: one group of results in `/admin/api/search`. `SearchProviderRegistry` asks it
 * for at most five results of a query of at least two characters, and only when the viewer has `requiredRole()`.
 */
#[AutoconfigureTag('admin.search_provider')]
interface SearchProvider
{
    /**
     * The group's heading.
     */
    public function label(): TranslatableInterface;

    /**
     * @return list<SearchResult>
     */
    public function search(string $q, int $limit): array;

    /**
     * Higher comes first.
     */
    public function priority(): int;

    public function requiredRole(): string;
}
