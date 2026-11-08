<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared;

use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\PublicRouteFixtures;
use App\Tests\Support\Inquiries\InquiryFormFields;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * S10, ADM-6: only /admin ever starts a session. This walks every other route with real parameters
 * and all its methods, so a new public route is covered or fails here, never skipped.
 *
 * The jar carries a Merkliste cookie written by JS: the routes that may answer it can, and a cached
 * page must not. A public page that started a session would answer `private`, which the
 * cacheable check catches; the session itself is saved and closed before the test can look at it,
 * so its usage index is what proves it was touched.
 */
final class PublicPagesStartNoSessionTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;
    use PublicRouteFixtures;

    /** Not part of a production build. */
    public const DEV_ONLY = ['_styleguide'];

    /** The `/_` routes that are public on purpose. */
    private const INTERNAL_ROUTES = ['_search_count', '_search_places', '_stats_collect'];

    /** The only routes that may answer with a cookie; each answers `private, no-store`. */
    private const COOKIE_ROUTES = ['merkliste', 'merkliste_toggle', 'merkliste_sync'];

    /** The routes outside the shared cache. Every other 2xx/3xx GET is `public` with an `s-maxage`. */
    private const PRIVATE_ROUTES = [...self::COOKIE_ROUTES, 'venue_phone', 'inquiry_submit', 'venue_report', 'health', '_stats_collect', 'venue_preview', 'venue_preview_short', 'crm_opt_out'];

    public function test_no_public_page_starts_a_session_or_sets_a_cookie(): void
    {
        self::mockTime(self::FROZEN_NOW);
        $data = $this->seedPublicRoutes();
        $this->client->getCookieJar()->set(new Cookie('merkliste', $data['number'].',', null, '/', 'localhost'));

        $parameters = [
            'venue_show' => [['slug' => DetailVenues::FULL_SLUG], ['slug' => self::HISTORY_SLUG]],
            'venue_markdown' => [['slug' => DetailVenues::FULL_SLUG], ['slug' => self::HISTORY_SLUG]],
            'venue_phone' => [['slug' => DetailVenues::FULL_SLUG]],
            'inquiry_submit' => [['slug' => DetailVenues::FULL_SLUG]],
            'listing_landing' => [['slug' => 'zuerich'], ['slug' => $data['building']]],
            'listing_town' => [['canton' => 'zuerich', 'town' => $data['town']]],
            'sitemap_venues' => [['n' => 1]],
            'merkliste_toggle' => [['number' => $data['number']]],
            'venue_preview' => [['token' => $data['preview']]],
            'venue_preview_short' => [['code' => $data['preview_short']]],
            'crm_opt_out' => [['token' => $data['opt_out']]],
        ];
        $posted = ['_token' => 'csrf-token'] + InquiryFormFields::valid(['started' => $data['started']]);
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $walked = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $canonical = $route->getDefault('_canonical_route') ?? $name;
            \assert(\is_string($canonical));
            if (self::isBehindAFirewall($route) || \in_array($canonical, self::DEV_ONLY, true)) {
                continue;
            }
            if (str_starts_with($route->getPath(), '/_') && !\in_array($canonical, self::INTERNAL_ROUTES, true)) {
                self::fail("Add {$canonical} to DEV_ONLY or INTERNAL_ROUTES.");
            }

            $required = array_diff($route->compile()->getPathVariables(), array_keys($route->getDefaults()));
            if ([] !== $required && !isset($parameters[$canonical])) {
                self::fail("Add {$canonical} to PARAMETERS.");
            }

            foreach ($parameters[$canonical] ?? [[]] as $sample) {
                foreach ($route->getMethods() ?: ['GET'] as $method) {
                    $this->walk($canonical, $method, $router->generate($name, $sample), $posted);
                }
            }
            $walked[] = $canonical;
        }

        self::assertSame([], array_diff(array_keys($parameters), $walked));
        self::assertSame([], array_diff(self::PRIVATE_ROUTES, $walked));
    }

    /**
     * @param array<string, mixed> $posted
     */
    private function walk(string $route, string $method, string $url, array $posted): void
    {
        $label = $method.' '.$url;
        $this->client->request($method, $url, 'POST' === $method ? $posted : [], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $response = $this->client->getResponse();
        $request = $this->client->getRequest();

        self::assertLessThan(400, $response->getStatusCode(), $label.' answers from a real parameter.');

        $session = $request->hasSession() ? $request->getSession() : null;
        self::assertFalse($session?->isStarted() ?? false, $label.' starts no session.');
        if ($session instanceof Session) {
            self::assertSame(0, $session->getUsageIndex(), $label.' never touches the session.');
        }
        self::assertFalse($request->cookies->has('PHPSESSID'), $label);

        if (\in_array($route, self::COOKIE_ROUTES, true)) {
            self::assertTrue($response->headers->hasCacheControlDirective('private'), $label.' is private.');
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'), $label.' has no-store.');
        } else {
            self::assertSame([], $response->headers->getCookies(), $label.' sets no cookie.');
        }

        if ('GET' === $method && !\in_array($route, self::PRIVATE_ROUTES, true)) {
            self::assertTrue($response->headers->hasCacheControlDirective('public'), $label.' is public.');
            self::assertTrue($response->headers->hasCacheControlDirective('s-maxage'), $label.' has an s-maxage.');
        }
    }

    private static function isBehindAFirewall(Route $route): bool
    {
        return 1 === preg_match('#^/(admin|konto)(/|$)#', $route->getPath());
    }

    public function test_a_logged_in_staff_member_stays_anonymous_on_public_pages(): void
    {
        $staff = $this->createStaff();
        self::assertIsString($staff['secret']);
        $this->logInFully($staff['user'], $staff['secret']);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $cookies = $this->client->getCookieJar()->all();
        self::assertNotEmpty($cookies);
        foreach ($cookies as $cookie) {
            self::assertSame('/admin', $cookie->getPath());
        }

        $this->client->request('GET', '/');

        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame([], $this->client->getRequest()->cookies->all());
    }
}
