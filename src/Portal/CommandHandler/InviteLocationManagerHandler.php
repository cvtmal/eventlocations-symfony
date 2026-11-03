<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\InviteLocationManager;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Exception\PortalEmailTaken;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The account and its access in one transaction, as `CreateOwnLocationHandler` does it. The controller looked the
 * address up first; a lost race on `uk_portal_users_email` ends in `PortalEmailTaken`.
 */
final class InviteLocationManagerHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(InviteLocationManager $command): void
    {
        $now = $this->clock->now();
        $user = new PortalUser($command->portalUserId, $command->email, $command->firstName, $command->lastName);
        $access = new PortalVenueAccess(
            $user,
            $this->em->getReference(Venue::class, $command->venueId) ?? throw new \LogicException('Venue has no subclass, so a reference always resolves.'),
            $now,
        );
        $access->confirm($now);
        $this->em->persist($user);
        $this->em->persist($access);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw PortalEmailTaken::onInvitation();
        }
    }
}
