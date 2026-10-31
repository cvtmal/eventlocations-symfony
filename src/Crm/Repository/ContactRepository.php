<?php

declare(strict_types=1);

namespace App\Crm\Repository;

use App\Crm\Entity\Contact;
use App\Crm\Exception\ContactNotFound;
use App\Crm\ValueObject\ContactEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Loads the contact under `SELECT … FOR UPDATE` (inside the bus transaction).
     *
     * @throws ContactNotFound
     */
    public function lock(string $id): Contact
    {
        $entity = Uuid::isValid($id) ? $this->find($id, LockMode::PESSIMISTIC_WRITE) : null;

        return $entity ?? throw ContactNotFound::forId($id);
    }

    /**
     * Whether another contact has the address (compared canonically). The unique key still decides
     * under concurrency.
     */
    public function emailTaken(string $email, ?string $exceptId = null): bool
    {
        $email = ContactEmail::normalise($email);
        if (null === $email) {
            return false;
        }

        $contact = $this->findOneBy(['email' => $email]);

        return null !== $contact && $contact->id() !== $exceptId;
    }
}
