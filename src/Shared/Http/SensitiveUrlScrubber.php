<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry's `before_send` (config/packages/sentry.yaml): an error report keeps the request URL, and on
 * a page whose path is a secret, the secret goes. Today that is G5's preview link, `/vorschau/{token}`,
 * and its short form `/v/{code}` (G11), which must never be logged (Wave G §4a.4). The report itself still goes out.
 */
final class SensitiveUrlScrubber
{
    /** A path prefix whose next segment is a secret. */
    private const array SECRET_SEGMENT_AFTER = ['/vorschau/', '/konto/abo-kuendigen/', '/v/'];

    public const string FILTERED = '[Filtered]';

    public function __invoke(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        if (isset($request['url']) && \is_string($request['url'])) {
            $request['url'] = self::scrub($request['url']);
            $event->setRequest($request);
        }

        return $event;
    }

    /**
     * The URL (or path) with the secret segment replaced and any query string dropped.
     */
    public static function scrub(string $url): string
    {
        foreach (self::SECRET_SEGMENT_AFTER as $prefix) {
            $url = (string) preg_replace('~'.preg_quote($prefix, '~').'[^/?#]+[^#]*~', $prefix.self::FILTERED, $url);
        }

        return $url;
    }
}
