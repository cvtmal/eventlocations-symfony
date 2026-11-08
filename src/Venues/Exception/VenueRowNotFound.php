<?php

declare(strict_types=1);

namespace App\Venues\Exception;

use App\Shared\Exception\DomainException;

/**
 * The venue has no photo, room or document with this id (#25, D21). A row of another
 * venue counts as not found, so an id from one record never reaches another. The CMS answers
 * 404.
 */
final class VenueRowNotFound extends DomainException
{
    private function __construct(
        public readonly int $venueNumber,
        public readonly string $kind,
        public readonly string $rowId,
    ) {
        parent::__construct(\sprintf('Venue %d has no %s "%s".', $venueNumber, $kind, $rowId));
    }

    public static function photo(int $venueNumber, string $photoId): self
    {
        return new self($venueNumber, 'photo', $photoId);
    }

    public static function room(int $venueNumber, string $roomId): self
    {
        return new self($venueNumber, 'room', $roomId);
    }

    public static function document(int $venueNumber, string $documentId): self
    {
        return new self($venueNumber, 'document', $documentId);
    }

    public static function video(int $venueNumber, string $videoId): self
    {
        return new self($venueNumber, 'video', $videoId);
    }

    public static function preview(int $venueNumber, string $previewId): self
    {
        return new self($venueNumber, 'preview', $previewId);
    }
}
