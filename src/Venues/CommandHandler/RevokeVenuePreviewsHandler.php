<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RevokeVenuePreviews;
use App\Venues\Entity\VenuePreview;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final class RevokeVenuePreviewsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RevokeVenuePreviews $command): int
    {
        // Whole seconds, as the TIMESTAMP(0) column keeps them (PostgreSQL would round, not cut).
        $at = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $count = 0;
        foreach ($this->em->getRepository(VenuePreview::class)->findBy(['venue' => $command->venueId, 'revokedAt' => null]) as $preview) {
            $preview->revoke($at);
            ++$count;
        }

        return $count;
    }
}
