<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Exception\ContactNotFound;
use App\Crm\Repository\ActivityRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Erases a contact (S13, D16), the one way both EraseContact and #29's purge do it. Inside the
 * caller's transaction: the contact's row is locked, the company's e-mail is blanked when it is
 * this contact's address, every entry on its timeline is anonymised (title, body and payload
 * blanked), the contact is deleted (its tasks and timeline links cascade, its company stays), and
 * the entries then on no timeline are deleted. An entry also on the company's timeline stays there,
 * anonymised.
 *
 * A linked venue may hold the same person (`contact_person`, `inquiry_email`). Crm writes no venue
 * data, so that copy stays; the erase dialog tells the admin to remove it in the CMS.
 *
 * It writes with SQL, so it never loads the contact; a caller that holds the Contact or Company
 * entity in the same unit of work must not flush it afterwards.
 */
final class ContactEraser
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActivityRepository $activities,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws ContactNotFound
     */
    public function erase(string $contactId): void
    {
        $contact = Uuid::isValid($contactId)
            ? $this->connection->fetchAssociative('SELECT company_id, email FROM crm_contacts WHERE id = ? FOR UPDATE', [$contactId])
            : false;
        if (false === $contact) {
            throw ContactNotFound::forId($contactId);
        }

        $now = $this->clock->now();
        if (null !== $contact['company_id'] && null !== $contact['email']) {
            $this->connection->executeStatement(
                'UPDATE crm_companies SET email = NULL, updated_at = ? WHERE id = ? AND lower(email) = ?',
                [$now->format('Y-m-d H:i:sO'), $contact['company_id'], $contact['email']],
            );
        }

        $activityIds = $this->activities->idsLinkedToContact($contactId);
        $this->activities->anonymise($activityIds, $now);
        $this->connection->executeStatement('DELETE FROM crm_contacts WHERE id = ?', [$contactId]);
        $this->activities->deleteUnlinked($activityIds);
    }
}
