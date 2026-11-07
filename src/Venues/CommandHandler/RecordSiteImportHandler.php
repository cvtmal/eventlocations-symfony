<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RecordSiteImport;
use App\Venues\Entity\PhotoCandidate;
use App\Venues\Entity\SourceRecord;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\SourceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores a website crawl (G3). The earlier current record of the same URL becomes `superseded`; the new record
 * and its candidates are the current ones. No public cache is touched: nothing public reads these tables.
 */
final class RecordSiteImportHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RecordSiteImport $command): string
    {
        $this->em->createQuery('UPDATE '.SourceRecord::class.' r SET r.status = :superseded, r.updatedAt = :now WHERE r.url = :url AND r.status = :fetched')
            ->setParameter('superseded', SourceStatus::Superseded)
            ->setParameter('fetched', SourceStatus::Fetched)
            ->setParameter('url', $command->url)
            ->setParameter('now', new \DateTimeImmutable())
            ->execute();

        $venue = null === $command->venueId ? null : $this->em->getReference(Venue::class, $command->venueId);
        $record = new SourceRecord($venue, $command->sourceType, $command->url, $command->facts, $command->report, $command->fetchedAt);
        $this->em->persist($record);

        foreach ($command->candidates as $position => $candidate) {
            $this->em->persist(new PhotoCandidate($record, $candidate, $position + 1));
        }

        return $record->id();
    }
}
