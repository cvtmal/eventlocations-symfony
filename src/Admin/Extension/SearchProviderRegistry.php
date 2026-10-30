<?php

declare(strict_types=1);

namespace App\Admin\Extension;

use App\Admin\ValueObject\SearchGroup;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SearchProviderRegistry
{
    private const int LIMIT = 5;

    /**
     * @param iterable<SearchProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('admin.search_provider')]
        private readonly iterable $providers,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * Asks every provider the viewer may see; one that finds nothing has no group.
     *
     * @return list<SearchGroup>
     */
    public function search(string $q): array
    {
        $groups = [];
        foreach (PriorityOrder::visible($this->providers, $this->authorization) as $provider) {
            $results = \array_slice($provider->search($q, self::LIMIT), 0, self::LIMIT);
            if ([] !== $results) {
                $groups[] = new SearchGroup($provider->label(), $results);
            }
        }

        return $groups;
    }
}
