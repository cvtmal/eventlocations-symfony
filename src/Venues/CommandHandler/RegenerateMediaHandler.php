<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Media\Exception\InvalidMediaPath;
use App\Media\Exception\MissingOriginal;
use App\Media\Exception\UnreadableImage;
use App\Media\ImageVariantGenerator;
use App\Media\MediaPaths;
use App\Media\MediaPublisher;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\RegenerateMedia;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\RegenerateMediaResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Venue by venue: renders every photo's variants from its original straight into
 * `public/media/<number>/` (the id is known), records the new size, colour and variants,
 * and re-publishes every document. A missing or unreadable original is listed and skipped;
 * the rest goes on. Flushes and clears after each venue.
 */
final class RegenerateMediaHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueRepository $venues,
        private readonly EntityManagerInterface $em,
        private readonly ImageVariantGenerator $generator,
        private readonly MediaPaths $paths,
        private readonly MediaPublisher $publisher,
    ) {
    }

    public function __invoke(RegenerateMedia $command): RegenerateMediaResult
    {
        $numbers = $this->venues->numbers($command->venueNumber);
        if (null !== $command->venueNumber && [] === $numbers) {
            return new RegenerateMediaResult(0, 0, 0, [], true);
        }

        $photos = 0;
        $documents = 0;
        $failures = [];
        foreach ($numbers as $number) {
            $venue = $this->venues->findOneBy(['number' => $number]);
            \assert($venue instanceof Venue);

            foreach ($this->em->getRepository(VenuePhoto::class)->findBy(['venue' => $venue], ['room' => 'ASC', 'position' => 'ASC']) as $photo) {
                try {
                    $photo->recordVariants($this->generator->generate(
                        $this->paths->original($photo->originalPath()),
                        $this->paths->photoDirectory($number),
                        $photo->id(),
                    ));
                    ++$photos;
                } catch (MissingOriginal|UnreadableImage|InvalidMediaPath $exception) {
                    $failures[] = \sprintf('%d/%s: %s', $number, $photo->id(), $exception->getMessage());
                }
            }

            foreach ($this->em->getRepository(VenueDocument::class)->findBy(['venue' => $venue], ['position' => 'ASC']) as $document) {
                try {
                    $this->publisher->publishDocument($document->path(), $number, $document->id());
                    ++$documents;
                } catch (MissingOriginal|InvalidMediaPath $exception) {
                    $failures[] = \sprintf('%d/%s: %s', $number, $document->id(), $exception->getMessage());
                }
            }

            $this->em->flush();
            $this->em->clear();
        }

        return new RegenerateMediaResult(\count($numbers), $photos, $documents, $failures, false);
    }
}
