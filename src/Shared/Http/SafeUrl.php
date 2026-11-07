<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exception\FetchRefused;

/**
 * An http(s) URL that passed the structural checks of SafeFetcher, rebuilt from its parts. The request goes out with
 * `__toString()`, never with the string the caller (or a Location header) supplied, so the client and this class
 * cannot read one URL two ways.
 *
 * Refused here: any scheme but http and https, credentials, control characters, spaces and backslashes in an
 * absolute URL, hosts that are not a plain DNS name (IP addresses in any spelling, `localhost`, one-label names,
 * a final label with a digit, reserved suffixes) and anything over 2,000 bytes. Ports and DNS are SafeFetcher's:
 * they depend on its policy. The fragment is dropped, dot segments are removed and non-ASCII bytes are
 * percent-encoded.
 */
final readonly class SafeUrl implements \Stringable
{
    public const int MAX_LENGTH = 2000;

    /** Suffixes that name machines or networks of an organisation, never the public web. */
    private const array RESERVED_SUFFIXES = [
        'local', 'localhost', 'internal', 'localdomain', 'lan', 'home', 'corp', 'intranet', 'private', 'arpa', 'onion',
    ];

    private function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public ?string $query,
    ) {
    }

    /**
     * @throws FetchRefused
     */
    public static function parse(string $url): self
    {
        if (\strlen($url) > self::MAX_LENGTH) {
            throw FetchRefused::because(RefusalReason::Malformed, 'URL longer than '.self::MAX_LENGTH.' bytes');
        }
        if (1 === preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            throw FetchRefused::because(RefusalReason::Malformed, 'control character, space or backslash in the URL');
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'])) {
            throw FetchRefused::because(RefusalReason::Malformed, 'not an absolute URL');
        }

        $scheme = strtolower($parts['scheme']);
        if ('http' !== $scheme && 'https' !== $scheme) {
            throw FetchRefused::because(RefusalReason::Scheme, self::show($scheme).' is not http or https');
        }

        // parse_url and a browser can disagree about where the host starts; any "@" before the first slash is
        // refused instead of interpreted.
        if (isset($parts['user']) || isset($parts['pass']) || 1 === preg_match('~^[a-z]+://[^/?\#]*@~i', $url)) {
            throw FetchRefused::because(RefusalReason::Credentials, 'the URL carries credentials');
        }

        $host = self::host($parts['host'] ?? '');
        $path = self::cleanPath($parts['path'] ?? '');
        $query = isset($parts['query']) && '' !== $parts['query'] ? self::encode($parts['query']) : null;

        $safe = new self($scheme, $host, $parts['port'] ?? null, $path, $query);
        if (\strlen((string) $safe) > self::MAX_LENGTH) {
            throw FetchRefused::because(RefusalReason::Malformed, 'URL longer than '.self::MAX_LENGTH.' bytes once encoded');
        }

        return $safe;
    }

    /**
     * A reference from a document or a Location header, resolved against this URL (RFC 3986 section 5) and checked
     * like any other URL. Tabs and line breaks are dropped and spaces encoded, as a browser does.
     *
     * @throws FetchRefused
     */
    public function resolve(string $reference): self
    {
        $reference = (string) preg_replace('/[\t\r\n]/', '', trim($reference));
        $reference = str_replace(' ', '%20', $reference);

        if ('' === $reference || str_starts_with($reference, '#')) {
            return $this;
        }
        if (str_starts_with($reference, '//')) {
            return self::parse($this->scheme.':'.$reference);
        }
        if (1 === preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
            return self::parse($reference);
        }

        $base = $this->origin();
        if (str_starts_with($reference, '?')) {
            return self::parse($base.$this->path.self::beforeFragment($reference));
        }
        if (str_starts_with($reference, '/')) {
            return self::parse($base.self::beforeFragment($reference));
        }

        $directory = substr($this->path, 0, (int) strrpos($this->path, '/') + 1);

        return self::parse($base.$directory.self::beforeFragment($reference));
    }

    public function effectivePort(): int
    {
        return $this->port ?? ('https' === $this->scheme ? 443 : 80);
    }

    /** `https://host` with an explicit port only when the URL has one. */
    public function origin(): string
    {
        return $this->scheme.'://'.$this->host.(null !== $this->port ? ':'.$this->port : '');
    }

    /** The host without a leading "www.": `example.ch` and `www.example.ch` are one site. */
    public function siteKey(): string
    {
        return str_starts_with($this->host, 'www.') ? substr($this->host, 4) : $this->host;
    }

    /** The same origin at another path, without a query. */
    public function withPath(string $path): self
    {
        return new self($this->scheme, $this->host, $this->port, $path, null);
    }

    public function pathAndQuery(): string
    {
        return $this->path.(null !== $this->query ? '?'.$this->query : '');
    }

    public function __toString(): string
    {
        return $this->origin().$this->pathAndQuery();
    }

    /**
     * @throws FetchRefused
     */
    private static function host(string $host): string
    {
        $host = strtolower($host);
        if ('' === $host) {
            throw FetchRefused::because(RefusalReason::Malformed, 'the URL has no host');
        }

        $literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if (false !== filter_var($literal, \FILTER_VALIDATE_IP)) {
            $blocked = (new AddressPolicy())->blockedReason($literal);
            throw null !== $blocked ? FetchRefused::because(RefusalReason::BlockedAddress, $literal.' is a '.$blocked.' address') : FetchRefused::because(RefusalReason::HostName, $literal.' is an IP address, not a host name');
        }

        if (1 === preg_match('/[^\x00-\x7F]/', $host)) {
            $ascii = idn_to_ascii($host, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
            if (false === $ascii) {
                throw FetchRefused::because(RefusalReason::HostName, 'invalid international host name');
            }
            $host = $ascii;
        }

        // One root dot is the same host; a second one is an empty label and fails below.
        $host = preg_replace('/\.$/', '', $host) ?? $host;

        $labels = explode('.', $host);
        $tld = end($labels);
        $valid = \strlen($host) <= 253
            && \count($labels) >= 2
            && 1 === preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld)
            && [] === array_filter($labels, static fn (string $label): bool => 1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label));
        if (!$valid || \in_array($tld, self::RESERVED_SUFFIXES, true)) {
            throw FetchRefused::because(RefusalReason::HostName, self::show($host).' is not a public host name');
        }

        return $host;
    }

    private static function cleanPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('..' === $segment) {
                array_pop($segments);
            } elseif ('.' !== $segment) {
                $segments[] = $segment;
            }
        }
        $clean = implode('/', $segments);
        // A trailing "." or ".." names a directory.
        if (1 === preg_match('~/\.{1,2}$~', $path) && !str_ends_with($clean, '/')) {
            $clean .= '/';
        }

        return self::encode('/' === ($clean[0] ?? '') ? $clean : '/'.$clean);
    }

    /** Percent-encodes what a URL cannot carry (non-ASCII bytes and a few ASCII ones) and keeps existing escapes. */
    private static function encode(string $part): string
    {
        return (string) preg_replace_callback(
            '/[^\x21-\x7E]|["<>\\\\^`{|}]/',
            static fn (array $match): string => \sprintf('%%%02X', \ord($match[0])),
            $part,
        );
    }

    private static function beforeFragment(string $reference): string
    {
        $position = strpos($reference, '#');

        return false === $position ? $reference : substr($reference, 0, $position);
    }

    /** A value from the URL, safe to print in a report. */
    private static function show(string $value): string
    {
        return PlainText::clean($value, 80);
    }
}
