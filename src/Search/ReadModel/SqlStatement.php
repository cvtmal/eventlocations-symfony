<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * An SQL statement with its bound parameters, as Connection::fetch*() takes them. Exposed so
 * the performance test can EXPLAIN exactly what the listing runs.
 */
final readonly class SqlStatement
{
    /**
     * @param array<string, mixed>                            $params
     * @param array<string, ArrayParameterType|ParameterType> $types
     */
    public function __construct(
        public string $sql,
        public array $params,
        public array $types,
    ) {
    }
}
