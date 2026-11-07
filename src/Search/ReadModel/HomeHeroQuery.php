<?php

declare(strict_types=1);

namespace App\Search\ReadModel;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use App\Venues\PublicPhotoPolicy;
use Doctrine\DBAL\Connection;

/**
 * The owner's hero photo (Q18), found by the hash of its original file, which the path ends in.
 * Only a venue-level photo of a published venue qualifies, so unpublishing the venue takes the
 * photo off the page, and it needs a live rights grant (`PublicPhotoPolicy`). One query.
 */
final class HomeHeroQuery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationJoin $translationJoin,
        private readonly EnabledLocale $enabledLocale,
    ) {
    }

    public function photo(string $sha256, string $locale): ?HomeHero
    {
        $published = new ListingSql(SearchCriteria::none(), $this->enabledLocale->default());
        $translation = $this->translationJoin->localized('venue_photo_translations', 'venue_photo_id', 'vp.id', 'pt');
        $granted = PublicPhotoPolicy::sql('vp');

        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT v.number, v.name, to_jsonb(vp) AS photo, pt.alt
                FROM {$published->from}
                JOIN venue_photos vp ON vp.venue_id = v.id AND vp.room_id IS NULL
                {$translation}
                WHERE {$published->where} AND vp.original_path LIKE :path AND {$granted}
                ORDER BY v.number, vp.position
                LIMIT 1
                SQL,
            $published->params + $this->translationJoin->parameters($locale) + ['path' => '%/'.$sha256.'.%'],
            $published->types,
        );
        if (false === $row) {
            return null;
        }

        \assert(\is_int($row['number']));
        \assert(\is_string($row['name']));
        \assert(\is_string($row['photo']));
        \assert(null === $row['alt'] || \is_string($row['alt']));
        $photo = json_decode($row['photo'], true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($photo));

        return new HomeHero(CardPhoto::fromRow($row['number'], $photo, $row['alt']), $row['name']);
    }
}
