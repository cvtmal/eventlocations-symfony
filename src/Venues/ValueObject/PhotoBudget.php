<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * Which photos a plan makes public (F12): the one rule of the detail page and the sitemap, so the sitemap never
 * advertises an image the page hides.
 *
 * The budget is `photos_max` photos in total. The gallery (the venue-level photos) comes first, by position; then the
 * first photo of each visible room, in room order, while budget is left. The visible rooms are the first `rooms_max`
 * rooms by position, so a hidden room takes its photo with it. Everything else stays stored and hidden.
 */
final readonly class PhotoBudget
{
    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param list<T>          $gallery   the venue-level photos in position order
     * @param list<string>     $roomIds   every room of the venue in position order
     * @param array<string, T> $roomFirst the first photo of a room by room id; a room without a photo is absent
     *
     * @return array{list<T>, array<string, T>} the public gallery, and the public room photos by room id in room order
     */
    public static function cut(array $gallery, array $roomIds, array $roomFirst, int $photosMax, int $roomsMax): array
    {
        $visibleGallery = \array_slice($gallery, 0, max(0, $photosMax));
        $budget = $photosMax - \count($visibleGallery);

        $visibleRoomPhotos = [];
        foreach (\array_slice($roomIds, 0, max(0, $roomsMax)) as $roomId) {
            if ($budget <= 0) {
                break;
            }
            if (isset($roomFirst[$roomId])) {
                $visibleRoomPhotos[$roomId] = $roomFirst[$roomId];
                --$budget;
            }
        }

        return [$visibleGallery, $visibleRoomPhotos];
    }
}
