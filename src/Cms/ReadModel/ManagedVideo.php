<?php

declare(strict_types=1);

namespace App\Cms\ReadModel;

use App\Venues\ValueObject\VideoProvider;

/**
 * One row of the Videos tab (G15), by position. `watchUrl` opens the video at its provider; the back office never
 * embeds the player.
 */
final readonly class ManagedVideo
{
    public string $watchUrl;

    public function __construct(
        public string $id,
        public int $position,
        public VideoProvider $provider,
        public string $videoId,
    ) {
        $this->watchUrl = $provider->watchUrl($videoId);
    }
}
