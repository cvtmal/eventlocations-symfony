<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\DecidePhotoCandidates;
use App\Venues\Entity\PhotoCandidate;
use App\Venues\VenueRows;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final class DecidePhotoCandidatesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRows $rows,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(DecidePhotoCandidates $command): void
    {
        $venue = $this->rows->venue($command->venueNumber);
        $ids = array_values(array_filter([...$command->approvedIds, ...$command->rejectedIds], Uuid::isValid(...)));
        if ([] === $ids) {
            return;
        }

        $at = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $candidates = $this->em->createQueryBuilder()
            ->select('c')
            ->from(PhotoCandidate::class, 'c')
            ->join('c.sourceRecord', 's')
            ->where('s.venue = :venue')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('venue', $venue->id())
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            \assert($candidate instanceof PhotoCandidate);
            \in_array($candidate->id(), $command->approvedIds, true) ? $candidate->approve($at) : $candidate->reject($at);
        }
    }
}
