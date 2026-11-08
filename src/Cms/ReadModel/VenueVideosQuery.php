<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\VideoProvider;
use Doctrine\DBAL\Connection;

/**
 * The Videos tab's list (G15): every stored video of the venue, by position, the hidden ones above the plan's limit
 * included (the page marks them).
 */
final class VenueVideosQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VenueIdQuery $venues,
    ) {
    }

    /**
     * @return ?list<ManagedVideo> null for an unknown venue
     */
    public function forVenue(int $number): ?array
    {
        $venueId = $this->venues->byNumber($number);
        if (null === $venueId) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, position, provider, video_id FROM venue_videos WHERE venue_id = :id ORDER BY position',
            ['id' => $venueId],
        );

        return array_map(static function (array $row): ManagedVideo {
            \assert(\is_string($row['id']) && \is_int($row['position']) && \is_string($row['provider']) && \is_string($row['video_id']));

            return new ManagedVideo($row['id'], $row['position'], VideoProvider::from($row['provider']), $row['video_id']);
        }, $rows);
    }
}
