<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The Location a contact row belongs to, for the redirect from a former contact page (#55 D8). One statement.
 */
final class ContactLocationQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Null when the contact is gone (an erased couple, a removed row) or the id is no UUID, so no 22P02 reaches a 500.
     */
    public function companyIdOf(string $contactId): ?string
    {
        if (!Uuid::isValid($contactId)) {
            return null;
        }
        $companyId = $this->connection->fetchOne('SELECT company_id FROM crm_contacts WHERE id = ?', [$contactId]);

        return false === $companyId ? null : (string) $companyId;
    }
}
