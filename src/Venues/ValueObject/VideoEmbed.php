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
 * would have to store as well. isUnlistedVimeo() tells that case apart, so the form can say what to do about it.
 */
final readonly class VideoEmbed
{
    private const array YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com'];
    private const array NOCOOKIE_HOSTS = ['youtube-nocookie.com', 'www.youtube-nocookie.com'];
    private const array VIMEO_HOSTS = ['vimeo.com', 'www.vimeo.com'];
    private const int MAX_LENGTH = 500;
    /** `/embed/videoseries?list=…` is a playlist, though the word fits YouTube's id shape. */
    private const string PLAYLIST = 'videoseries';

    private function __construct(
        public VideoProvider $provider,
        public string $videoId,
    ) {
    }

    public static function of(VideoProvider $provider, string $videoId): ?self
    {
        if (VideoProvider::YouTube === $provider && self::PLAYLIST === $videoId) {
            return null;
        }

        return $provider->isValidId($videoId) ? new self($provider, $videoId) : null;
    }

    public static function fromUrl(string $input): ?self
    {
        $link = self::parse($input);
        if (null === $link) {
            return null;
        }
        [$host, $segments, $query] = $link;

        if (\in_array($host, self::YOUTUBE_HOSTS, true)) {
            if (['watch'] === $segments) {
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
            return 2 === \count($segments) && 'video' === $segments[0] && !isset($query['h']) ? self::of(VideoProvider::Vimeo, $segments[1]) : null;
        }

        return null;
    }

    /**
     * True for a Vimeo video set to "unlisted" (`vimeo.com/<number>/<hash>`, `player.vimeo.com/video/<number>?h=<hash>`):
     * fromUrl() refuses it, and the manager can fix it by making the video public.
     */
    public static function isUnlistedVimeo(string $input): bool
    {
        $link = self::parse($input);
        if (null === $link) {
            return false;
        }
        [$host, $segments, $query] = $link;

        if (\in_array($host, self::VIMEO_HOSTS, true)) {
            return 2 === \count($segments) && VideoProvider::Vimeo->isValidId($segments[0]) && self::isUnlistedHash($segments[1]);
        }
        if ('player.vimeo.com' === $host) {
            return 2 === \count($segments) && 'video' === $segments[0] && VideoProvider::Vimeo->isValidId($segments[1])
                && \is_string($query['h'] ?? null) && self::isUnlistedHash($query['h']);
        }

        return false;
    }

    /**
     * @return array{string, list<string>, array<mixed>}|null host (lower case), the non-empty path segments and the query
     */
    private static function parse(string $input): ?array
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
        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $segment): bool => '' !== $segment));
        parse_str($parts['query'] ?? '', $query);

        return [strtolower($parts['host']), $segments, $query];
    }

    /** Vimeo's privacy hash: hex digits, ten of them today. */
    private static function isUnlistedHash(string $hash): bool
    {
        return 1 === preg_match('/^[0-9a-f]{6,32}$/Di', $hash);
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
