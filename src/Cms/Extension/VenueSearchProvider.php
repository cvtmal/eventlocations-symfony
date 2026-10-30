<?php

declare(strict_types=1);

namespace App\Cms\Extension;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchResult;
use App\Cms\ReadModel\VenueListQuery;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The venue group of the global ⌘K search: the list's search (name, town, ZIP, number), unpublished venues
 * included, each linking to its editor.
 */
final readonly class VenueSearchProvider implements SearchProvider
{
    public function __construct(
        private VenueListQuery $venues,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('search.label', [], 'cms');
    }

    public function search(string $q, int $limit): array
    {
        $results = [];
        foreach ($this->venues->quickSearch($q, $limit) as $row) {
            $detail = $row->place->isKnown()
                ? $this->translator->trans('list.row.number_place', ['number' => $row->number, 'place' => $row->place->trans($this->translator)], 'cms')
                : $this->translator->trans('list.row.number', ['number' => $row->number], 'cms');

            $results[] = new SearchResult(
                'venue',
                $row->name,
                $detail,
                $this->urls->generate('admin_locations_edit', ['number' => $row->number]),
            );
        }

        return $results;
    }

    public function priority(): int
    {
        return 300;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
