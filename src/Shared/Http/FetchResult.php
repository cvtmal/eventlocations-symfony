<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * One fetched page: the URL it ended on, its body (undecoded text in `$charset`, null when the server named none)
 * and the URLs the request was redirected through.
 */
final readonly class FetchResult
{
    /**
     * @param list<string> $redirects the URLs passed on the way, the final one excluded
     */
    public function __construct(
        public SafeUrl $url,
        public int $status,
        public string $contentType,
        public ?string $charset,
        public string $body,
        public array $redirects = [],
    ) {
    }
}
