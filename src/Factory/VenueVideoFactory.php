<?php

declare(strict_types=1);

namespace App\Factory;

use App\Venues\Entity\VenueVideo;
use App\Venues\ValueObject\VideoEmbed;
use App\Venues\ValueObject\VideoProvider;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * A YouTube video with a fictional id (no such video exists, G15: demo data never points at somebody's real video);
 * position defaults to 1. Pass `embed` for another provider or id.
 *
 * @extends PersistentObjectFactory<VenueVideo>
 */
final class VenueVideoFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return VenueVideo::class;
    }

    public static function youtube(string $id): VideoEmbed
    {
        return VideoEmbed::of(VideoProvider::YouTube, $id) ?? throw new \InvalidArgumentException($id);
    }

    public static function vimeo(string $id): VideoEmbed
    {
        return VideoEmbed::of(VideoProvider::Vimeo, $id) ?? throw new \InvalidArgumentException($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'venue' => VenueFactory::new(),
            'position' => 1,
            'embed' => self::youtube('demo'.self::faker()->unique()->regexify('[A-Za-z0-9]{7}')),
        ];
    }
}
