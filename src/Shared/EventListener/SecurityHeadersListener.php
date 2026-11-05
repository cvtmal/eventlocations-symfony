<?php

declare(strict_types=1);

namespace App\Shared\EventListener;

use App\Shared\Security\ContentSecurityPolicy;
use App\Shared\Security\CspNonce;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SEC-1 on every main response, `/admin` included. The priority puts it after ResponseListener
 * (0), which fills in Content-Type, and before Symfony's own CSP removal and the toolbar (-128):
 * ErrorListener and ProfilerController already lift the policy from debug pages, so there is no
 * debug branch here, and a page re-rendered at 422 keeps its CSP.
 *
 * One route (`nearby`, #52) opts into `ContentSecurityPolicy::GOOGLE_MAPS` through its `_csp`
 * default; everything else keeps `POLICY`.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -10)]
final class SecurityHeadersListener
{
    private const string POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";

    public function __construct(
        private readonly CspNonce $nonce,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $maps = ContentSecurityPolicy::GOOGLE_MAPS === $event->getRequest()->attributes->get(ContentSecurityPolicy::ROUTE_ATTRIBUTE);
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Not no-referrer: #13's no-JS toggle and #14's CSRF fallback read the same-origin Referer.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', $maps ? ContentSecurityPolicy::GOOGLE_MAPS_PERMISSIONS : 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (str_starts_with((string) $headers->get('Content-Type'), 'text/html')) {
            // The maps policy never takes a nonce: one would disable its style 'unsafe-inline'.
            $headers->set('Content-Security-Policy', $maps ? ContentSecurityPolicy::GOOGLE_MAPS_POLICY : $this->policy());
        }
    }

    private function policy(): string
    {
        if (!$this->nonce->used()) {
            return self::POLICY;
        }

        return str_replace("script-src 'self'", \sprintf("script-src 'self' 'nonce-%s'", $this->nonce->value()), self::POLICY);
    }
}
