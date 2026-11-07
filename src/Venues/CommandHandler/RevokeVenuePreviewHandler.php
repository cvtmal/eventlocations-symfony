<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RevokeVenuePreview;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * G5: ends a preview link. The page is never cached, so the next request already answers 410.
 */
final class RevokeVenuePreviewHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRows $rows,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws VenueNotFound
     * @throws VenueRowNotFound when the id is no preview link of the venue
     */
    public function __invoke(RevokeVenuePreview $command): void
    {
        $venue = $this->rows->venue($command->venueNumber);
        // Whole seconds, as the TIMESTAMP(0) column keeps them (PostgreSQL would round, not cut).
        $this->rows->preview($venue, $command->previewId)->revoke(new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp()));
        $this->em->flush();
    }
}
