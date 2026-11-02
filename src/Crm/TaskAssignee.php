<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;

/**
 * Who gets a task nobody wrote (D24): the first active one of the record's owners, else the oldest active admin. A
 * deactivated owner is a real case.
 */
final class TaskAssignee
{
    public function __construct(private readonly AdminUserRepository $users)
    {
    }

    public function among(?AdminUser ...$owners): ?AdminUser
    {
        foreach ([...$owners, $this->users->oldestActiveAdmin()] as $candidate) {
            if (null !== $candidate && $candidate->isActive()) {
                return $candidate;
            }
        }

        return null;
    }
}
