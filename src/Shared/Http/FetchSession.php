<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exception\FetchRefused;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * One crawl of one site, with its own budget. Everything SafeFetcher promises happens here:
 *
 * - every URL goes through SafeUrl, and every request resolves the host again, refuses the whole answer when any
 *   address in it is not public, and connects to one vetted address by the client's `resolve` option, so the
 *   transport never asks DNS itself and a rebinding answer cannot swap the address after the check;
 * - redirects are never followed by the client (`max_redirects` 0): each Location is resolved, checked like a new
 *   URL and counted, at most `maxRedirects` of them;
 * - after the first page, every URL (and every redirect) must stay on the site the first page ended on;
 * - robots.txt is read once per host and obeyed for every URL, including redirect targets;
 * - at least `minDelaySeconds` (or the host's Crawl-delay) between two requests to a host;
 * - a body is read in chunks and cut off at the byte caps; only the allowed content types are read at all.
 *
 * It holds state for one crawl and is not a service: SafeFetcher::open() hands out a new one.
 */
final class FetchSession
{
    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private readonly float $startedAt;
    private int $pages = 0;
    private int $bytes = 0;
    private ?string $siteKey = null;
    private readonly AddressPolicy $addressPolicy;

    /** @var array<string, float> host => when the last request to it ended */
    private array $lastRequestEnd = [];

    /** @var array<string, RobotsTxt> */
    private array $robots = [];

    /** @var array<string, true> every address that passed the check in this crawl, in canonical text form */
    private array $vetted = [];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly HostResolver $resolver,
        private readonly ClockInterface $clock,
        private readonly string $userAgent,
        private readonly FetchLimits $limits,
        private readonly FetchPolicy $policy,
    ) {
        $this->addressPolicy = new AddressPolicy();
        $this->startedAt = $this->now();
    }

    /**
     * Fetches an HTML page. The first call starts the crawl and may be redirected anywhere public; every later call
     * must stay on the site the first one ended on.
     *
     * @throws FetchRefused
     */
    public function get(string $url): FetchResult
    {
        $target = SafeUrl::parse($url);
        if ($this->pages >= $this->limits->maxPages) {
            throw FetchRefused::because(RefusalReason::PageLimit, 'at most '.$this->limits->maxPages.' pages per site');
        }
        ++$this->pages;

        $entry = null === $this->siteKey;
        $this->siteKey ??= $target->siteKey();
        $result = $this->follow($target, $this->limits->maxBytesPerResponse, ['text/html', 'application/xhtml+xml'], true, !$entry);
        if ($entry) {
            $this->siteKey = $result->url->siteKey();
        }

        return $result;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function seconds(): float
    {
        return $this->now() - $this->startedAt;
    }

    /**
     * @param list<string> $contentTypes accepted types; `text/*` style patterns allowed; '' (none sent) only in a list that holds ''
     *
     * @throws FetchRefused
     */
    private function follow(SafeUrl $start, int $cap, array $contentTypes, bool $checkRobots, bool $sameSiteOnly): FetchResult
    {
        $current = $start;
        $seen = [(string) $start];
        $redirects = [];

        for ($hops = 0;; ++$hops) {
            if ($sameSiteOnly && $current->siteKey() !== $this->siteKey) {
                throw FetchRefused::because(RefusalReason::CrossHost, $current->host.' is not part of the site');
            }
            if ($checkRobots) {
                $this->assertRobotsAllow($current);
            }

            $exchange = $this->exchange($current, $cap, $contentTypes);
            if (null !== $exchange['body']) {
                return new FetchResult($current, $exchange['status'], $exchange['type'], $exchange['charset'], $exchange['body'], $redirects);
            }

            if ($hops >= $this->limits->maxRedirects) {
                throw FetchRefused::because(RefusalReason::Redirect, 'more than '.$this->limits->maxRedirects.' redirects');
            }
            $location = $exchange['location'];
            if (null === $location || '' === trim($location)) {
                throw FetchRefused::because(RefusalReason::Redirect, 'a redirect without a Location');
            }
            try {
                $next = $current->resolve($location);
            } catch (FetchRefused $e) {
                throw FetchRefused::because($e->reason, 'redirect to '.$e->detail, $e);
            }
            if (\in_array((string) $next, $seen, true)) {
                throw FetchRefused::because(RefusalReason::Redirect, 'a redirect loop');
            }
            $seen[] = (string) $next;
            $redirects[] = (string) $current;
            $current = $next;
        }
    }

    /**
     * One request. Returns the body for a 2xx answer, a null body with the Location for a redirect, and throws for
     * everything else.
     *
     * @param list<string> $contentTypes
     *
     * @return array{status: int, type: string, charset: ?string, body: ?string, location: ?string}
     *
     * @throws FetchRefused
     */
    private function exchange(SafeUrl $url, int $cap, array $contentTypes): array
    {
        $address = $this->vet($url);
        $this->waitForTurn($url->host);

        $changedTo = null;
        $response = null;
        try {
            $response = $this->client->request('GET', (string) $url, [
                'max_redirects' => 0,
                'timeout' => $this->limits->idleTimeoutSeconds,
                'max_duration' => min($this->limits->requestSeconds, $this->secondsLeft()),
                'resolve' => [$url->host => $address],
                'no_proxy' => '*',
                'buffer' => false,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept' => implode(', ', array_filter($contentTypes)) ?: '*/*',
                    'Accept-Language' => 'de-CH,de;q=0.9,en;q=0.4',
                    // A compressed body can inflate to far more than its size on the wire before the cap sees it.
                    'Accept-Encoding' => 'identity',
                ],
                // The second line of defence: a connection to an address that never passed the check (a proxy, a
                // resolver of its own) is aborted. Any vetted address counts, not only the pinned one, because the
                // client may reuse a keep-alive connection made earlier or DNS may rotate its answers.
                'on_progress' => function (int $downloaded, int $total, array $info) use (&$changedTo): void {
                    $primary = $info['primary_ip'] ?? '';
                    if (\is_string($primary) && '' !== $primary && !$this->wasVetted($primary)) {
                        $changedTo = $primary;
                        throw new TransportException('the connection went to an address that was not vetted');
                    }
                },
            ]);

            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);

            if (\in_array($status, self::REDIRECTS, true)) {
                $response->cancel();

                return ['status' => $status, 'type' => '', 'charset' => null, 'body' => null, 'location' => $headers['location'][0] ?? null];
            }
            if ($status < 200 || $status > 299) {
                $response->cancel();

                throw FetchRefused::status($status);
            }

            [$type, $charset] = self::contentType($headers['content-type'][0] ?? '');
            if (!self::typeAllowed($type, $contentTypes)) {
                $response->cancel();

                throw FetchRefused::because(RefusalReason::ContentType, '' === $type ? 'the server named no content type' : $type.' is not an accepted content type');
            }

            $body = $this->read($response, $cap, (int) ($headers['content-length'][0] ?? 0));
        } catch (TransportExceptionInterface $e) {
            $response?->cancel();

            throw null !== $changedTo ? FetchRefused::because(RefusalReason::AddressChanged, 'connected to '.$changedTo.', which was not vetted', $e) : FetchRefused::because(RefusalReason::Transport, self::describe($e), $e);
        } finally {
            $this->lastRequestEnd[$url->host] = $this->now();
        }

        return ['status' => $status, 'type' => $type, 'charset' => $charset, 'body' => $body, 'location' => null];
    }

    /**
     * @throws FetchRefused
     * @throws TransportExceptionInterface
     */
    private function read(ResponseInterface $response, int $cap, int $declared): string
    {
        $room = $this->limits->maxTotalBytes - $this->bytes;
        $limit = min($cap, $room);
        $reason = $room < $cap ? RefusalReason::ByteBudget : RefusalReason::TooLarge;
        $detail = static fn (): string => 'more than '.$limit.' bytes';

        if ($declared > $limit) {
            $response->cancel();

            throw FetchRefused::because($reason, 'the server announces '.$declared.' bytes, '.$limit.' are allowed');
        }

        $body = '';
        foreach ($this->client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();

                throw FetchRefused::because(RefusalReason::Transport, 'the server stopped sending');
            }
            $body .= $chunk->getContent();
            if (\strlen($body) > $limit) {
                $response->cancel();

                throw FetchRefused::because($reason, $detail());
            }
        }

        $this->bytes += \strlen($body);

        return $body;
    }

    /**
     * Port, DNS and address checks. Returns the one address the request is pinned to.
     *
     * @throws FetchRefused
     */
    private function vet(SafeUrl $url): string
    {
        if (!$this->policy->allowAnyPort && null !== $url->port && $url->port !== ('https' === $url->scheme ? 443 : 80)) {
            throw FetchRefused::because(RefusalReason::Port, 'port '.$url->port.' is not allowed');
        }

        $addresses = $this->resolver->resolve($url->host);
        if ([] === $addresses) {
            throw FetchRefused::because(RefusalReason::Unresolvable, $url->host.' has no address');
        }

        foreach ($addresses as $address) {
            $blocked = $this->addressPolicy->blockedReason($address);
            if ('invalid' === $blocked || (null !== $blocked && !$this->policy->allowNonPublicAddresses)) {
                throw FetchRefused::because(RefusalReason::BlockedAddress, $url->host.' resolves to '.$address.' ('.$blocked.')');
            }
        }
        foreach ($addresses as $address) {
            $this->vetted[self::canonical($address)] = true;
        }

        // IPv4 first: a host without IPv6 routing would otherwise fail on the first address of a dual-stack answer.
        foreach ($addresses as $address) {
            if (false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                return $address;
            }
        }

        return $addresses[0];
    }

    /**
     * @throws FetchRefused
     */
    private function assertRobotsAllow(SafeUrl $url): void
    {
        $robots = $this->robots[$url->host] ??= $this->loadRobots($url);

        if (!$robots->allows($url->pathAndQuery())) {
            throw FetchRefused::because(RefusalReason::Robots, $robots->unreadableBecause() ?? $url->pathAndQuery().' is disallowed by robots.txt');
        }
        if (($robots->crawlDelay() ?? 0.0) > $this->limits->maxCrawlDelaySeconds) {
            throw FetchRefused::because(RefusalReason::Robots, 'Crawl-delay of '.$robots->crawlDelay().' s is above our limit of '.$this->limits->maxCrawlDelaySeconds.' s');
        }
    }

    private function loadRobots(SafeUrl $url): RobotsTxt
    {
        try {
            $result = $this->follow($url->withPath('/robots.txt'), $this->limits->maxRobotsBytes, ['text/*', ''], false, false);
        } catch (FetchRefused $e) {
            // RFC 9309: 4xx means there is no robots.txt; a file we cannot read otherwise means we cannot tell,
            // so we do not crawl. A refusal of the address or the URL itself is not about robots.txt and stays
            // what it is, so the report names the real reason.
            if (RefusalReason::HttpStatus === $e->reason && null !== $e->statusCode && $e->statusCode < 500 && 429 !== $e->statusCode) {
                return RobotsTxt::allowAll();
            }
            if (!\in_array($e->reason, [RefusalReason::HttpStatus, RefusalReason::Transport, RefusalReason::TooLarge, RefusalReason::ByteBudget, RefusalReason::ContentType, RefusalReason::Redirect], true)) {
                throw $e;
            }

            return RobotsTxt::unreadable('robots.txt could not be read ('.$e->getMessage().')');
        }

        // The file may have come from another host (apex to www): that host must not fetch it again.
        return $this->robots[$result->url->host] = RobotsTxt::parse($result->body);
    }

    /**
     * @throws FetchRefused
     */
    private function waitForTurn(string $host): void
    {
        $this->secondsLeft();
        $last = $this->lastRequestEnd[$host] ?? null;
        if (null === $last) {
            return;
        }

        $delay = max($this->limits->minDelaySeconds, ($this->robots[$host] ?? null)?->crawlDelay() ?? 0.0);
        $wait = $last + $delay - $this->now();
        if ($wait <= 0.0) {
            return;
        }
        if ($wait >= $this->secondsLeft()) {
            throw FetchRefused::because(RefusalReason::TimeLimit, 'no time left for the pause between two requests');
        }

        $this->clock->sleep($wait);
    }

    /**
     * @throws FetchRefused
     */
    private function secondsLeft(): float
    {
        $left = $this->limits->totalSeconds - $this->seconds();
        if ($left <= 0.0) {
            throw FetchRefused::because(RefusalReason::TimeLimit, 'the crawl took more than '.$this->limits->totalSeconds.' s');
        }

        return $left;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /**
     * @return array{string, ?string} the media type in lower case, and the charset parameter
     */
    private static function contentType(string $header): array
    {
        $parts = explode(';', $header);
        $charset = null;
        foreach (\array_slice($parts, 1) as $parameter) {
            if (1 === preg_match('/^\s*charset\s*=\s*"?([A-Za-z0-9_.:-]+)"?\s*$/i', $parameter, $match)) {
                $charset = $match[1];
            }
        }

        return [strtolower(trim($parts[0])), $charset];
    }

    /**
     * @param list<string> $allowed
     */
    private static function typeAllowed(string $type, array $allowed): bool
    {
        foreach ($allowed as $pattern) {
            if ($pattern === $type || ('' !== $pattern && '' !== $type && fnmatch($pattern, $type))) {
                return true;
            }
        }

        return false;
    }

    private function wasVetted(string $address): bool
    {
        return isset($this->vetted[self::canonical($address)]);
    }

    /** One spelling per address: `2606:2800:0220:1::1` and `2606:2800:220:1::1` are the same host. */
    private static function canonical(string $address): string
    {
        $packed = @inet_pton($address);

        return false === $packed ? $address : (string) inet_ntop($packed);
    }

    private static function describe(TransportExceptionInterface $e): string
    {
        // The message names the host and the cURL error; nothing in it came from the page.
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $e->getMessage()), 0, 160);
    }
}
