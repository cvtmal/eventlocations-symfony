<?php

declare(strict_types=1);

namespace App\Stats\Controller;

use App\Shared\Security\ClientIpKey;
use App\Shared\Translation\LocalizedRoutes;
use App\Stats\BeaconPayload;
use App\Stats\Spool;
use App\Stats\VisitorHash;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The statistics beacon (#47, contract C1): `POST /_stats` from `stats.js`, which appends the
 * events to the day's spool file.
 *
 * Deliberate deviation from `cqrs.md`: this write dispatches no command. `command.bus` wraps every
 * handler in `doctrine_transaction`, and its `beginTransaction()` connects to Postgres, which would
 * wake Neon on every page view. The beacon writes a file instead, and the nightly ingest (or the
 * staff button) loads the files through the bus. Nothing on this path may open a connection
 * (`CollectControllerTest::test_collecting_never_connects_to_the_database`).
 *
 * A foreign origin gets 403. A body over 8 KiB, a rate-limited client or no valid event gets 204
 * with nothing written: `sendBeacon` cannot read the answer. Never cached, never indexed, no
 * session, no cookie.
 */
final class CollectController extends AbstractController
{
    public const int MAX_BODY_BYTES = 8192;

    public function __construct(
        private readonly RateLimiterFactoryInterface $statsCollectLimiter,
        private readonly ClientIpKey $clientIpKey,
        private readonly VisitorHash $visitorHash,
        private readonly Spool $spool,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/_stats', name: '_stats_collect', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): Response
    {
        if (!self::isSameOrigin($request)) {
            return self::private(new Response(null, Response::HTTP_FORBIDDEN));
        }
        if (self::bodySize($request) > self::MAX_BODY_BYTES) {
            return self::private(new Response(null, Response::HTTP_NO_CONTENT));
        }
        if (!$this->statsCollectLimiter->create($this->clientIpKey->of($request))->consume()->isAccepted()) {
            return self::private(new Response(null, Response::HTTP_NO_CONTENT));
        }

        $now = $this->clock->now();
        $events = BeaconPayload::fromRequest($request, $now, $this->visitorHash->of($request, $now));
        if ([] !== $events) {
            $this->spool->append($events);
        }

        return self::private(new Response(null, Response::HTTP_NO_CONTENT));
    }

    /**
     * `Sec-Fetch-Site` when the browser sends it, else an `Origin` equal to ours.
     */
    private static function isSameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site) {
            return 'same-origin' === $site;
        }
        $origin = $request->headers->get('Origin');

        return null !== $origin && $origin === $request->getSchemeAndHttpHost();
    }

    /**
     * The declared length, else the body read (a chunked request has no length).
     */
    private static function bodySize(Request $request): int
    {
        $length = $request->headers->get('Content-Length');

        return null !== $length && ctype_digit($length) ? (int) $length : \strlen($request->getContent());
    }

    private static function private(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
