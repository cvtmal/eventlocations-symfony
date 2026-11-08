<?php

declare(strict_types=1);

namespace App\Venues\ReadModel;

use App\Venues\ValueObject\VideoProvider;

/**
 * One video of the Videos section (G15). `embedUrl` is the player the facade loads on the visitor's click only;
 * `watchUrl` is the provider's own page, the link that works without JavaScript.
 */
final readonly class DetailVideo
{
    public string $embedUrl;
    public string $watchUrl;

    public function __construct(
        public VideoProvider $provider,
        public string $videoId,
    ) {
        $this->embedUrl = $provider->embedUrl($videoId);
        $this->watchUrl = $provider->watchUrl($videoId);
    }
}
