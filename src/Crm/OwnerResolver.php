<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\Repository\AdminUserRepository;
use App\Crm\Exception\InvalidPropertyValue;
use Symfony\Component\Uid\Uuid;

/**
 * Turns an owner id from a form or an inline edit into the staff account (S13: an optional owner).
 * Only an active account can be made owner; one deactivated later stays where it is.
 */
final class OwnerResolver
{
    public function __construct(private readonly AdminUserRepository $users)
    {
    }

    /**
     * @throws InvalidPropertyValue when the id names no active staff account
     */
    public function resolve(?string $ownerId): ?AdminUser
    {
        if (null === $ownerId) {
            return null;
        }

        $owner = Uuid::isValid($ownerId) ? $this->users->find($ownerId) : null;
        if (null === $owner || !$owner->isActive()) {
            throw InvalidPropertyValue::unknown('owner');
        }

        return $owner;
    }
}
