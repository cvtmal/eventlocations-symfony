<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A video a venue embeds (G15): a provider and its video id, read from a URL staff or a manager paste. Only links of
 * the allowlist below are understood; anything else is null, and the handler refuses it. Neither the URL nor any
 * embed HTML is kept, so the page builds the player address from these two values alone.
 *
 * Understood: `youtube.com/watch?v=<id>` (also on `www.` and `m.`), `youtube.com/{embed,shorts,live}/<id>`,
 * `youtu.be/<id>`, `youtube-nocookie.com/embed/<id>`, `vimeo.com/<number>` and `player.vimeo.com/video/<number>`. The
 * scheme may be missing (`https` is assumed), `http` and `https` only, no user, password or port. An unlisted Vimeo
 * link (`vimeo.com/<number>/<hash>`, `player.vimeo.com/video/<number>?h=<hash>`) is refused: its hash is a secret we
 * would have to store as well.
 */
final readonly class VideoEmbed
{
    private const array YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];
    private const array NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];
    private const array VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];
    private const int MAX_LENGTH = 500;

    private function __construct(
        public VideoProvider $provider,
        public string $videoId,
    ) {
    }

    public static function of(VideoProvider $provider, string $videoId): ?self
    {
        return $provider->isValidId($videoId) ? new self($provider, $videoId) : null;
    }

    public static function fromUrl(string $input): ?self
    {
        $input = trim($input);
        if ('' === $input || \strlen($input) > self::MAX_LENGTH || 1 === preg_match('/\s/', $input)) {
            return null;
        }
        if (1 !== preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://'.$input;
        }
        $parts = parse_url($input);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        if (!\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $segment): bool => '' !== $segment));

        if (\in_array($host, self::YOUTUBE_HOSTS, true)) {
            if (['watch'] === $segments) {
                parse_str($parts['query'] ?? '', $query);

                return \is_string($query['v'] ?? null) ? self::of(VideoProvider::YouTube, $query['v']) : null;
            }

            return 2 === \count($segments) && \in_array($segments[0], ['embed', 'shorts', 'live'], true) ? self::of(VideoProvider::YouTube, $segments[1]) : null;
        }
        if ('youtu.be' === $host) {
            return 1 === \count($segments) ? self::of(VideoProvider::YouTube, $segments[0]) : null;
        }
        if (\in_array($host, self::NOCOOKIE_HOSTS, true)) {
            return 2 === \count($segments) && 'embed' === $segments[0] ? self::of(VideoProvider::YouTube, $segments[1]) : null;
        }
        if (\in_array($host, self::VIMEO_HOSTS, true)) {
            return 1 === \count($segments) ? self::of(VideoProvider::Vimeo, $segments[0]) : null;
        }
        if ('player.vimeo.com' === $host) {
            parse_str($parts['query'] ?? '', $query);

            return 2 === \count($segments) && 'video' === $segments[0] && !isset($query['h']) ? self::of(VideoProvider::Vimeo, $segments[1]) : null;
        }

        return null;
    }

    public function embedUrl(): string
    {
        return $this->provider->embedUrl($this->videoId);
    }

    public function watchUrl(): string
    {
        return $this->provider->watchUrl($this->videoId);
    }
}
