<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * A venue's Instagram profile, always linked as `https://www.instagram.com/<handle>` (#45 F-m3). It reads a handle,
 * `@handle` or a profile URL, and the shapes the old `UrlType` field stored for those (`https://@handle`,
 * `https://handle`), so existing rows link right at render time without a data migration. A handle is letters,
 * digits, `.` and `_`, at most 30. Anything else is no profile, `null`: a post or reel URL, another site, a dotted
 * host after a scheme (more likely a website than a handle), so no broken link is rendered.
 */
final readonly class InstagramProfile
{
    private const string HANDLE = '/\A[A-Za-z0-9._]{1,30}\z/';
    private const string INSTAGRAM_URL = '~\A(?:https?://)?(?:www\.|m\.)?instagram\.com/@?([^/?#]*)(?:[/?#].*)?\z~is';
    private const string BARE = '~\A(https?://)?(@?)([^/?#:]*)/?\z~i';

    /** First path segments that are Instagram's own pages, not a profile. */
    private const array NOT_PROFILES = ['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct'];

    private function __construct(
        public string $handle,
    ) {
    }

    public static function fromInput(?string $input): ?self
    {
        $input = trim((string) $input);
        if (1 === preg_match(self::INSTAGRAM_URL, $input, $match)) {
            return self::handle($match[1]);
        }
        if (1 !== preg_match(self::BARE, $input, $match)) {
            return null;
        }
        // After a scheme, a dot without `@` names a website, not a handle.
        if ('' !== $match[1] && '' === $match[2] && str_contains($match[3], '.')) {
            return null;
        }

        return self::handle($match[3]);
    }

    public function url(): string
    {
        return 'https://www.instagram.com/'.$this->handle;
    }

    private static function handle(string $candidate): ?self
    {
        if (1 !== preg_match(self::HANDLE, $candidate) || \in_array(strtolower($candidate), self::NOT_PROFILES, true)) {
            return null;
        }

        return new self($candidate);
    }
}
