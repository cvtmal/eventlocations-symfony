<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The video hosts a venue may embed (G15), stored in `venue_videos.provider` (chk_venue_videos_provider). Each case
 * knows its id shape (the same rule as chk_venue_videos_video_id), the player URL the facade loads after the click and
 * the page a visitor opens without JavaScript.
 *
 * YouTube plays from `youtube-nocookie.com` (YouTube's privacy-enhanced mode); Vimeo gets `dnt=1`, so its player sets
 * no tracking cookies. Both start playing at once, since the player only loads on the visitor's click.
 */
enum VideoProvider: string
{
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';

    public function isValidId(string $id): bool
    {
        return 1 === preg_match(match ($this) {
            self::YouTube => '/^[A-Za-z0-9_-]{11}$/D',
            self::Vimeo => '/^[1-9][0-9]{0,11}$/D',
        }, $id);
    }

    public function embedUrl(string $id): string
    {
        return match ($this) {
            self::YouTube => 'https://www.youtube-nocookie.com/embed/'.rawurlencode($id).'?autoplay=1',
            self::Vimeo => 'https://player.vimeo.com/video/'.rawurlencode($id).'?dnt=1&autoplay=1',
        };
    }

    public function watchUrl(string $id): string
    {
        return match ($this) {
            self::YouTube => 'https://www.youtube.com/watch?v='.rawurlencode($id),
            self::Vimeo => 'https://vimeo.com/'.rawurlencode($id),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Vimeo => 'Vimeo',
        };
    }
}
