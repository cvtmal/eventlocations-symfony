<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * The one route-scoped policy (#52, owner Q1): the near-me page loads the Google Maps JavaScript API.
 * It is Google's documented allowlist variant without a nonce, because a nonce would make the cached
 * page impossible (`CspNonce`), and without script `'unsafe-inline'`, because our loader is a file.
 * Its `style-src 'unsafe-inline'` works only while no hash or nonce sits in `style-src` (MDN: either
 * makes the browser ignore `'unsafe-inline'`), so this policy must never take one.
 *
 * A route opts in through its `_csp` default; `SecurityHeadersListener` reads it. `geolocation=(self)`
 * is granted on that route only.
 */
final class ContentSecurityPolicy
{
    public const string ROUTE_ATTRIBUTE = '_csp';
    public const string GOOGLE_MAPS = 'google_maps';
    public const string GOOGLE_MAPS_POLICY = "default-src 'none'; script-src 'self' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com https://*.googleusercontent.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' data: blob: https://*.googleapis.com https://*.google.com https://*.gstatic.com; worker-src blob:; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";
    public const string GOOGLE_MAPS_PERMISSIONS = 'camera=(), microphone=(), geolocation=(self), payment=()';
}
