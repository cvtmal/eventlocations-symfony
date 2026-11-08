<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\InviteLocationManager;
use App\Portal\Entity\PortalUser;
use App\Portal\Entity\PortalVenueAccess;
use App\Portal\Exception\PortalEmailTaken;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RevokeVenuePreviews;
use App\Venues\Entity\Venue;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * The account and its access in one transaction, as `CreateOwnLocationHandler` does it. The controller looked the
 * address up first; a lost race on `uk_portal_users_email` ends in `PortalEmailTaken`.
 */
final class InviteLocationManagerHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly MessageBusInterface $commandBus,
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

        // A managed venue is claimed (G6, D18): its preview links end and its detail page drops the label.
        $this->commandBus->dispatch(new RevokeVenuePreviews($command->venueId));
        $this->commandBus->dispatch(new PurgeVenueCache($access->venue()->number()), [new DispatchAfterCurrentBusStamp()]);
    }
}
