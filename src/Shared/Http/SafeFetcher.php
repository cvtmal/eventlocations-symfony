<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches pages of a website that somebody else typed in (G3, `app:venues:import-site`), so it assumes the URL is
 * hostile. A building block with no module dependency: the module that crawls decides what to do with the pages.
 *
 * `open()` starts one crawl, a FetchSession with its own budget; the session's class comment lists what it
 * enforces (http and https on port 80 and 443 only, no credentials, every host resolved and vetted again on every
 * request and redirect, the connection pinned to the vetted address, manual redirects, robots.txt, a pause
 * between requests, byte, page and time caps, a content type allowlist). Nothing is cached between sessions and no
 * cookie or credential of the application is ever sent: the requests carry a User-Agent that names us and a way to
 * reach us, and nothing else.
 *
 * `FetchSession::resource()` is the one other use (D12, places-photos): a call to an API the caller chose, with the
 * caller's headers on the first request only and without robots.txt. A caller with its own budget builds its own
 * instance with its own FetchLimits.
 */
final class SafeFetcher
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly HostResolver $resolver,
        private readonly ClockInterface $clock,
        #[Autowire('deine-eventlocation.ch site-import (+mailto:%site.contact_email%)')]
        private readonly string $userAgent,
        private readonly FetchLimits $limits = new FetchLimits(),
        private readonly FetchPolicy $policy = new FetchPolicy(),
    ) {
    }

    /**
     * `$limits` replaces the crawl's caps for a session that is no crawl (G6 fetches a handful of photos).
     */
    public function open(?FetchLimits $limits = null): FetchSession
    {
        return new FetchSession($this->httpClient, $this->resolver, $this->clock, $this->userAgent, $limits ?? $this->limits, $this->policy);
    }
}
