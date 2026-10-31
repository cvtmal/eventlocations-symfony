<?php

declare(strict_types=1);

namespace App\Venues;

use App\Media\ImageVariantGenerator;
use App\Media\MediaPublisher;
use App\Shared\Uid\EntityId;
use App\Venues\Entity\Room;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenuePhoto;
use App\Venues\ValueObject\StagedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adds a photo to a venue (#5 pipeline, #9 P7): render the variants into staging → store the
 * original, both before the first DB write → VenuePhoto → flush (the venue has its number)
 * → copyVariants() into public/media/<number>/. Usage:
 *
 *     $staging = $pipeline->openStaging();
 *     try {
 *         $staged = $pipeline->stage($source, $originalPath, $staging.'/0');
 *         $photo = $pipeline->add($venue, $room, $position, $staged);
 *         $em->flush();
 *         $pipeline->publish($photo, $staged, $published); // on failure: $published->revert()
 *     } finally {
 *         $pipeline->discardStaging($staging);
 *     }
 *
 * Callers: the content import (ImportVenueContentHandler) and #25 AddVenuePhoto.
 */
final class PhotoPipeline
{
    private const string BASENAME = 'v';

    public function __construct(
        private readonly MediaPublisher $publisher,
        private readonly ImageVariantGenerator $generator,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.import_staging_dir%')]
        private readonly string $stagingRoot,
    ) {
    }

    /**
     * A new, not yet existing staging directory for one write.
     */
    public function openStaging(): string
    {
        return rtrim($this->stagingRoot, '/').'/'.EntityId::generate();
    }

    /**
     * Renders the variants of $sourceFile into $directory, then stores the original under MEDIA_ROOT (kept when
     * it exists). In that order, because a file can pass the header check and still not decode (#25, D6), and a
     * memory fatal runs no catch: a failed render leaves no original, and $sourceFile is either the upload's temp
     * file, which PHP removes itself, or the import's own. An original's path is content-addressed, so a kept one
     * has the bytes of $sourceFile.
     */
    public function stage(string $sourceFile, string $originalPath, string $directory): StagedPhoto
    {
        $image = $this->generator->generate($sourceFile, $directory, self::BASENAME);
        $this->publisher->storeOriginal($sourceFile, $originalPath);

        return new StagedPhoto($originalPath, $image, $directory);
    }

    public function add(Venue $venue, ?Room $room, int $position, StagedPhoto $staged): VenuePhoto
    {
        $photo = new VenuePhoto($venue, $room, $position, $staged->originalPath, $staged->image);
        $this->em->persist($photo);

        return $photo;
    }

    /**
     * Copies the staged variants to the public directory; the photo must be flushed (its
     * venue has a number). The files are tracked in $published before the copy starts.
     */
    public function publish(VenuePhoto $photo, StagedPhoto $staged, PublishedMedia $published): void
    {
        $number = $photo->venue()->number();
        $published->photo($number, $photo->id());
        $this->publisher->copyVariants($staged->image, $staged->directory, self::BASENAME, $number, $photo->id());
    }

    /**
     * Removes a staging directory with everything in it (links are removed, not followed).
     */
    public function discardStaging(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $directory.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->discardStaging($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
