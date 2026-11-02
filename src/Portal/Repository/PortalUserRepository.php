<?php

declare(strict_types=1);

namespace App\Portal\Repository;

use App\Portal\Entity\PortalUser;
use App\Portal\Exception\PortalUserNotFound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortalUser>
 */
class PortalUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortalUser::class);
    }

    public function get(string $id): PortalUser
    {
        return $this->find($id) ?? throw PortalUserNotFound::forId($id);
    }

    public function findOneByEmail(string $email): ?PortalUser
    {
        return $this->findOneBy(['email' => mb_strtolower($email)]);
    }
}
