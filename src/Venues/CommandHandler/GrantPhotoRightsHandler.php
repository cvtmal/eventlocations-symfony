<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\PhotoGrants;
use App\Venues\ValueObject\PhotoGrant;
use App\Venues\VenueEditing;
use App\Venues\VenueRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * G1: grants every named photo (or every photo) of the venue that has no live grant, in one
 * transaction, under the declaration text in force. The photos become public with the commit, so
 * the venue and the listings are purged and the photos are touched (the sitemap's lastmod is the
 * newest of the venue's public photos). The import guard is left alone (`markCmsEdited: false`):
 * a grant changes no content the folder import would overwrite.
 */
final class GrantPhotoRightsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly VenueEditing $editing,
        private readonly VenueRows $rows,
        private readonly PhotoGrants $grants,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return int how many photos were granted
     *
     * @throws VenueNotFound
     * @throws VenueRowNotFound when an id is not a photo of the venue
     */
    public function __invoke(GrantPhotoRights $command): int
    {
        $venue = $this->editing->lock($command->venueNumber);
        foreach ($command->photoIds ?? [] as $photoId) {
            $this->rows->photo($venue, $photoId);
        }

        $ungranted = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT vp.id FROM venue_photos vp
                WHERE vp.venue_id = :venue
                  AND NOT EXISTS (SELECT 1 FROM media_rights_grants rg WHERE rg.venue_photo_id = vp.id AND rg.revoked_at IS NULL)
                ORDER BY vp.room_id NULLS FIRST, vp.position
                SQL,
            ['venue' => $venue->id()],
        );
        $wanted = array_map(strval(...), $ungranted);
        if (null !== $command->photoIds) {
            $wanted = array_values(array_filter($wanted, static fn (string $id): bool => \in_array($id, $command->photoIds, true)));
        }
        if ([] === $wanted) {
            return 0;
        }

        $how = new PhotoGrant($command->grantor, $command->evidence, $command->derivatives);
        foreach ($wanted as $photoId) {
            $photo = $this->rows->photo($venue, $photoId);
            // The sitemap's lastmod follows the venue's public photos; nothing else moves here.
            $photo->touch();
            $this->grants->issue($photo, $how);
        }
        $this->em->flush();
        $this->editing->close($venue, purgeListings: true, markCmsEdited: false);

        return \count($wanted);
    }
}
