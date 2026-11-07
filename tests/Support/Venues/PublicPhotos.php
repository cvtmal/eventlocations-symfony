<?php

declare(strict_types=1);

namespace App\Tests\Support\Venues;

use App\Media\ValueObject\Picture;
use App\Search\ReadModel\CardPhoto;
use App\Search\ReadModel\ListingCard;
use App\Search\ReadModel\ListingQuery;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ReadModel\SimilarVenuesQuery;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Venues\ReadModel\VenueDetailQuery;
use App\Venues\ReadModel\VenueSitemapQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the public read models show of the photos of one published venue (G1): the ids, in the order
 * each reader gives them. One place for the readers a revoke or a grant has to reach, so a test
 * names the surfaces it expects and a new public photo reader is added here once.
 *
 * Run it inside a KernelTestCase (it builds the read models by hand, like their own tests).
 */
final class PublicPhotos
{
    /** More than any test uploads, so a card's own limit of 8 does not hide a photo. */
    private const int ALL = 100;

    /**
     * @param ?string $neighbourId the id of another published venue: the one the "similar venues" are asked for
     *
     * @return array{listing: list<string>, merkliste: list<string>, similar: list<string>, detail: list<string>, rooms: list<string>, sitemap: list<string>}
     */
    public static function of(Connection $connection, TranslatorInterface $translator, int $venueNumber, ?string $neighbourId = null): array
    {
        $locale = ListingQueryTest::enabledLocale();
        $translation = new TranslationJoin($locale);
        $listing = new ListingQuery($connection, $translation, $locale, new MockClock('2026-10-30 10:00:00', 'UTC'));

        $cards = array_values(array_filter(
            $listing->results(SearchCriteria::none(), 'de')->cards,
            static fn (ListingCard $card): bool => $card->number === $venueNumber,
        ));
        $saved = $listing->cardsByNumbers([$venueNumber], 'de', self::ALL);

        $detail = new VenueDetailQuery($connection, $translation, $locale, $translator)->detail($venueNumber, 'de')
            ?? throw new \LogicException(\sprintf('Venue %d has no public detail page.', $venueNumber));
        $rooms = [];
        foreach ($detail->rooms as $room) {
            if (null !== $room->photo) {
                $rooms[] = $room->photo->photoId;
            }
        }

        return [
            'listing' => self::ids($cards[0]->photos ?? []),
            'merkliste' => self::ids($saved[0]->photos ?? []),
            'similar' => self::similar($connection, $locale, $translation, $venueNumber, $neighbourId),
            'detail' => array_map(static fn (Picture $picture): string => $picture->photoId, $detail->photos),
            'rooms' => $rooms,
            'sitemap' => self::sitemap($connection, $locale, $venueNumber),
        ];
    }

    /**
     * @param list<CardPhoto> $photos
     *
     * @return list<string>
     */
    private static function ids(array $photos): array
    {
        return array_map(static fn (CardPhoto $photo): string => $photo->photoId, $photos);
    }

    /**
     * @return list<string> the photo of the venue's card among the similar venues of the neighbour, if it has one
     */
    private static function similar(Connection $connection, EnabledLocale $locale, TranslationJoin $translation, int $venueNumber, ?string $neighbourId): array
    {
        if (null === $neighbourId) {
            return [];
        }

        foreach (new SimilarVenuesQuery($connection, $translation, $locale)->forVenue($neighbourId, 'de') as $similar) {
            if ($similar->number === $venueNumber && null !== $similar->photo) {
                return [$similar->photo->photoId];
            }
        }

        return [];
    }

    /**
     * @return list<string> the photo ids of the venue's `<image:image>` entries
     */
    private static function sitemap(Connection $connection, EnabledLocale $locale, int $venueNumber): array
    {
        $ids = [];
        foreach (new VenueSitemapQuery($connection, $locale)->page(1) as $venue) {
            foreach ($venue->imageUrls as $url) {
                if (1 === preg_match('#^/media/'.$venueNumber.'/([0-9a-f-]{36})-\d+\.jpg$#', $url, $match)) {
                    $ids[] = $match[1];
                }
            }
        }

        return $ids;
    }
}
