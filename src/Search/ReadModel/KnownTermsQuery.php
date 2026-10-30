<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Loads the taxonomy vocabulary (about 90 rows) once per request for the search codec.
 * Tagged kernel.reset by autoconfiguration, so a long-running worker reloads it.
 */
final class KnownTermsQuery implements ResetInterface
{
    private ?KnownTerms $terms = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function all(): KnownTerms
    {
        return $this->terms ??= KnownTerms::fromPairs(
            $this->connection->fetchAllAssociative('SELECT term_group, code FROM taxonomy_terms ORDER BY term_group, position'),
        );
    }

    public function reset(): void
    {
        $this->terms = null;
    }
}
