<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\EventListener;

use App\Shared\EventListener\SecurityHeadersListener;
use App\Shared\Security\ContentSecurityPolicy;
use App\Shared\Security\CspNonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersListenerTest extends TestCase
{
    private const string POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";
    /** A literal copy, so a change to the constant shows up as a diff here. */
    private const string MAPS_POLICY = "default-src 'none'; script-src 'self' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com https://*.googleusercontent.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' data: blob: https://*.googleapis.com https://*.google.com https://*.gstatic.com; worker-src blob:; form-action 'self'; base-uri 'none'; frame-src https://www.google.com; frame-ancestors 'none'";
    private const array MAPS_ROUTE = [ContentSecurityPolicy::ROUTE_ATTRIBUTE => ContentSecurityPolicy::GOOGLE_MAPS];
    /** #53: the maps policy plus `frame-src https://*.google.com` (Google's CSP guide), a literal copy as above. */
    private const string PLACES_POLICY = "default-src 'none'; script-src 'self' 'unsafe-eval' blob: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com https://*.googleusercontent.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.googleusercontent.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' data: blob: https://*.googleapis.com https://*.google.com https://*.gstatic.com; worker-src blob:; form-action 'self'; base-uri 'none'; frame-src https://www.google.com https://*.google.com; frame-ancestors 'none'";
    private const array PLACES_RESPONSE = [ContentSecurityPolicy::ROUTE_ATTRIBUTE => ContentSecurityPolicy::GOOGLE_PLACES];

    public function test_an_html_response_gets_the_exact_policy_and_the_plain_headers(): void
    {
        $response = $this->respond(new CspNonce());

        self::assertSame(self::POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_a_used_nonce_only_changes_script_src(): void
    {
        $nonce = new CspNonce();
        $value = $nonce->value();

        $response = $this->respond($nonce);

        self::assertSame(
            str_replace("script-src 'self'", "script-src 'self' 'nonce-".$value."'", self::POLICY),
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function test_an_unused_nonce_leaves_no_token(): void
    {
        $response = $this->respond(new CspNonce());

        self::assertStringNotContainsString('nonce-', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_sub_request_is_left_alone(): void
    {
        $response = $this->respond(new CspNonce(), type: HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('X-Content-Type-Options'));
    }

    public function test_json_gets_the_plain_headers_and_no_policy(): void
    {
        $response = $this->respond(new CspNonce(), contentType: 'application/json');

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertTrue($response->headers->has('Permissions-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_plain_http_has_no_hsts(): void
    {
        self::assertFalse($this->respond(new CspNonce())->headers->has('Strict-Transport-Security'));
    }

    public function test_a_secure_request_has_hsts(): void
    {
        $response = $this->respond(new CspNonce(), server: ['HTTPS' => 'on']);

        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_the_maps_route_gets_the_maps_policy_and_geolocation_for_itself(): void
    {
        $response = $this->respond(new CspNonce(), attributes: self::MAPS_ROUTE);

        self::assertSame(self::MAPS_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame(ContentSecurityPolicy::GOOGLE_MAPS_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(self), payment=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_the_maps_route_never_takes_a_nonce(): void
    {
        $nonce = new CspNonce();
        $nonce->value();

        $response = $this->respond($nonce, attributes: self::MAPS_ROUTE);

        self::assertSame(self::MAPS_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('nonce-', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_places_value_gets_the_places_policy_without_geolocation(): void
    {
        $response = $this->respond(new CspNonce(), attributes: self::PLACES_RESPONSE);

        self::assertSame(self::PLACES_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame(ContentSecurityPolicy::GOOGLE_PLACES_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_the_places_value_never_takes_a_nonce(): void
    {
        $nonce = new CspNonce();
        $nonce->value();

        $response = $this->respond($nonce, attributes: self::PLACES_RESPONSE);

        self::assertSame(self::PLACES_POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('nonce-', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_an_unknown_csp_value_gets_the_site_policy(): void
    {
        $response = $this->respond(new CspNonce(), attributes: [ContentSecurityPolicy::ROUTE_ATTRIBUTE => 'other']);

        self::assertSame(self::POLICY, $response->headers->get('Content-Security-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), payment=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_json_on_the_maps_route_gets_its_permissions_and_no_policy(): void
    {
        $response = $this->respond(new CspNonce(), contentType: 'application/json', attributes: self::MAPS_ROUTE);

        self::assertSame('camera=(), microphone=(), geolocation=(self), payment=()', $response->headers->get('Permissions-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $attributes
     */
    private function respond(
        CspNonce $nonce,
        string $contentType = 'text/html; charset=UTF-8',
        int $type = HttpKernelInterface::MAIN_REQUEST,
        array $server = [],
        array $attributes = [],
    ): Response {
        $response = new Response('', Response::HTTP_OK, ['Content-Type' => $contentType]);
        $request = Request::create('/', server: $server);
        $request->attributes->add($attributes);
        $event = new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $type,
            $response,
        );

        (new SecurityHeadersListener($nonce))($event);

        return $response;
    }
}
