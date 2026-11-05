<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchResult;
use App\Crm\ReadModel\CompanyListQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The company group of the global ⌘K search (CRM-6, D20): the list's search, which also finds a Location by
 * its contact rows' names and e-mails (#55 D16), each result linking to the record. Below the venues' 300.
 */
final readonly class CompanySearchProvider implements SearchProvider
{
    public function __construct(
        private CompanyListQuery $companies,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('search.companies', [], 'crm');
    }

    public function search(string $q, int $limit): array
    {
        $results = [];
        foreach ($this->companies->quickSearch($q, $limit) as $row) {
            $results[] = new SearchResult(
                'company',
                $row->name,
                $row->stage->trans($this->translator),
                $this->urls->generate('admin_crm_companies_show', ['id' => $row->id]),
            );
        }

        return $results;
    }

    public function priority(): int
    {
        return 200;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
