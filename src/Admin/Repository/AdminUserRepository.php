<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Entity\AdminUser;
use App\Admin\Exception\AdminUserRuleViolation;
use App\Admin\ValueObject\AdminRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminUser>
 */
class AdminUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminUser::class);
    }

    public function findOneByEmail(string $email): ?AdminUser
    {
        return $this->findOneBy(['email' => mb_strtolower($email)]);
    }

    /**
     * @throws AdminUserRuleViolation when no such user exists
     */
    public function get(string $id): AdminUser
    {
        return $this->find($id) ?? throw AdminUserRuleViolation::notFound();
    }

    public function oldestActiveAdmin(): ?AdminUser
    {
        return $this->findOneBy(['role' => AdminRole::Admin, 'isActive' => true], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function countActiveAdmins(): int
    {
        return $this->count(['role' => AdminRole::Admin, 'isActive' => true]);
    }

    public function emailExists(string $email, ?string $exceptId = null): bool
    {
        $user = $this->findOneByEmail($email);

        return null !== $user && $user->id() !== $exceptId;
    }
}
