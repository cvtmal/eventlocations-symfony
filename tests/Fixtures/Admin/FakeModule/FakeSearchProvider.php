<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\SearchProvider;
use App\Admin\Extension\SearchResult;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Echoes the query it receives, so a test can see what the endpoint passed on.
 */
final class FakeSearchProvider implements SearchProvider
{
    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('search.label', [], 'probe_admin');
    }

    public function search(string $q, int $limit): array
    {
        if ('nichts' === $q) {
            return [];
        }

        return [new SearchResult('probe', $q, \sprintf('limit %d', $limit), '/admin/_probe/treffer')];
    }

    public function priority(): int
    {
        return 0;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
