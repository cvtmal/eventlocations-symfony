<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchResult;
use App\Crm\ReadModel\DealListQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The deal group of the global ⌘K search (D27): the list's search, each result linking to the record. Below the contacts.
 */
final readonly class DealSearchProvider implements SearchProvider
{
    public function __construct(
        private DealListQuery $deals,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('search.deals', [], 'crm');
    }

    public function search(string $q, int $limit): array
    {
        $results = [];
        foreach ($this->deals->quickSearch($q, $limit) as $hit) {
            $results[] = new SearchResult('deal', $hit->name, $hit->companyName, $this->urls->generate('admin_crm_deals_show', ['id' => $hit->id]));
        }

        return $results;
    }

    public function priority(): int
    {
        return 50;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
