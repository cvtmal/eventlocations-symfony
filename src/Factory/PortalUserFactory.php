<?php

declare(strict_types=1);

namespace App\Factory;

use App\Portal\Entity\PortalUser;
use App\Shared\Uid\EntityId;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A confirmed, active manager. The default hash matches no password: tests that log in use
 * PortalSession::createManager(). An unconfirmed manager has no password yet, as after registering.
 *
 * @extends PersistentObjectFactory<PortalUser>
 */
final class PortalUserFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return PortalUser::class;
    }

    public function unconfirmed(): self
    {
        return $this->with(['emailVerifiedAt' => null, 'passwordHash' => null]);
    }

    public function deactivated(): self
    {
        return $this->afterInstantiate(static fn (PortalUser $user) => $user->deactivate());
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => EntityId::generate(),
            'email' => self::faker()->unique()->safeEmail(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'passwordHash' => '!',
            'emailVerifiedAt' => new \DateTimeImmutable(),
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(Instantiator::withConstructor()->alwaysForce('emailVerifiedAt', 'passwordHash'));
    }
}
