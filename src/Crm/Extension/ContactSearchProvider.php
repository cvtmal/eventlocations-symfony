<?php

declare(strict_types=1);

namespace App\Crm\Extension;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchResult;
use App\Crm\ReadModel\ContactListQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The contact group of the global ⌘K search (CRM-6, D20): the list's search on the folded name and the
 * e-mail address, each result linking to the record. Below the companies.
 */
final readonly class ContactSearchProvider implements SearchProvider
{
    public function __construct(
        private ContactListQuery $contacts,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('search.contacts', [], 'crm');
    }

    public function search(string $q, int $limit): array
    {
        $results = [];
        foreach ($this->contacts->quickSearch($q, $limit) as $row) {
            $results[] = new SearchResult(
                'contact',
                $row->fullName(),
                $row->companyName ?? $row->email,
                $this->urls->generate('admin_crm_contacts_show', ['id' => $row->id]),
            );
        }

        return $results;
    }

    public function priority(): int
    {
        return 100;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
