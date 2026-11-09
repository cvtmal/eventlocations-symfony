<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Whether the CRM already knows a Location of the contact list (F14, `app:crm:import-contacts`): a Location of the
 * same name (any case), or a Location or contact that holds one of the row's addresses. A rerun of the import then
 * writes nothing.
 */
final readonly class LocationMatchQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $emails lower case
     */
    public function exists(string $name, array $emails): bool
    {
        return false !== $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM crm_companies WHERE lower(name) = lower(:name) OR email IN (:emails)
                UNION ALL
                SELECT 1 FROM crm_contacts WHERE email IN (:emails)
                LIMIT 1
                SQL,
            ['name' => $name, 'emails' => [] === $emails ? [''] : $emails],
            ['emails' => ArrayParameterType::STRING],
        );
    }
}
