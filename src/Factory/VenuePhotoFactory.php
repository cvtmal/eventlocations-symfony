<?php

declare(strict_types=1);

namespace App\Factory;

use App\Media\ValueObject\GeneratedImage;
use App\Media\ValueObject\ImageFormat;
use App\Media\ValueObject\ImageVariants;
use App\Venues\Entity\VenuePhoto;
use Zenstruck\Foundry\Object\Instantiator;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A venue-level photo by default (room NULL, position 1), 1600×1067 with widths 360–1280
 * in every format. Rows only: the factory writes no files (DemoStory and
 * `app:media:regenerate` do), so the default path is fictional.
 *
 * Every photo gets a live rights grant (G1), so it is public like the photos of the import;
 * `withoutGrant()` leaves it private, as a fresh upload is.
 *
 * @extends PersistentObjectFactory<VenuePhoto>
 */
final class VenuePhotoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenuePhoto::class;
    }

    public function withoutGrant(): self
    {
        return $this->with(['granted' => false]);
    }

    protected function initialize(): static
    {
        return $this
            ->instantiateWith(Instantiator::withConstructor()->allowExtra('granted'))
            ->afterPersist(static function (VenuePhoto $photo, array $parameters): void {
                if (false !== ($parameters['granted'] ?? true)) {
                    MediaRightsGrantFactory::createOne(['photo' => $photo]);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'room' => null,
            'position' => 1,
            'originalPath' => 'demo/'.self::faker()->unique()->slug(2).'.jpg',
            'image' => new GeneratedImage(1600, 1067, '#b9a58c', new ImageVariants([360, 640, 960, 1280], [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg])),
        ];
    }
}
