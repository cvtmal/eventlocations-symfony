<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\VenueFactory;
use App\Portal\Security\PortalArea;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * Two doors, two firewalls: a session of one is nothing to the other, and both live in one browser. Every login
 * here goes through the real forms, so the cookies have their real names and paths.
 */
final class PortalAdminSeparationTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
    }
    use Factories;

    /** The back office's cookie under `session.storage.factory.mock_file`; PHPSESSID on a real session. */
    private const string STAFF_SESSION_COOKIE = 'MOCKSESSID';

    public function test_a_manager_is_a_stranger_to_the_back_office(): void
    {
        $this->logInAsManager();

        foreach (['/admin', '/admin/benutzer', '/admin/konten'] as $path) {
            $this->assertTurnedAwayTo('/admin/login', $path);
        }
    }

    public function test_a_managers_session_opens_nothing_in_the_back_office_under_the_staff_cookie(): void
    {
        $this->logInAsManager();
        $this->presentSessionAs(self::STAFF_SESSION_COOKIE, '/admin', PortalArea::SESSION_COOKIE, PortalArea::COOKIE_PATH);

        foreach (['/admin', '/admin/konten'] as $path) {
            $this->assertTurnedAwayTo('/admin/login', $path);
            $this->assertRequestReadTheSessionOf('portal');
        }
    }

    public function test_staff_are_strangers_to_the_account_area(): void
    {
        $this->logInStaff();

        foreach (['/konto', '/konto/profil'] as $path) {
            $this->assertTurnedAwayTo('/konto/anmelden', $path);
        }
    }

    public function test_a_staff_session_opens_nothing_in_the_account_area_under_the_manager_cookie(): void
    {
        $this->logInStaff();
        $this->presentSessionAs(PortalArea::SESSION_COOKIE, PortalArea::COOKIE_PATH, self::STAFF_SESSION_COOKIE, '/admin');

        foreach (['/konto', '/konto/profil'] as $path) {
            $this->assertTurnedAwayTo('/konto/anmelden', $path);
            $this->assertRequestReadTheSessionOf('admin');
        }
    }

    public function test_both_sessions_live_side_by_side_and_end_one_at_a_time(): void
    {
        $this->logInStaff();
        $manager = $this->logInAsManager();
        PortalVenueAccessFactory::createOne(['user' => $manager, 'venue' => VenueFactory::new()->withoutLocation()]);

        $this->assertOpens('/admin');
        $this->assertOpens('/konto');

        $this->logOutOf('/konto');
        $this->assertOpens('/admin');
        $this->assertTurnedAwayTo('/konto/anmelden', '/konto');

        $this->logInAsManager($manager);
        $this->logOutOf('/admin');
        $this->assertOpens('/konto');
        $this->assertTurnedAwayTo('/admin/login', '/admin');
    }

    public function test_a_public_page_sets_no_cookie_after_both_logins(): void
    {
        $this->logInStaff();
        $this->logInAsManager();

        $this->client->request('GET', '/');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());

        $this->client->request('GET', '/eventlokale');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    private function logInStaff(): void
    {
        $staff = $this->createStaff();
        self::assertNotNull($staff['secret']);
        $this->logInFully($staff['user'], $staff['secret']);
    }

    /**
     * Each area reads only its own cookie name and both names reach one session store, so this is the one case
     * a firewall has to answer: the session's id under the name the other area reads, and nothing else in the jar.
     */
    private function presentSessionAs(string $name, string $path, string $ownName, string $ownPath): void
    {
        $jar = $this->client->getCookieJar();
        $own = $jar->get($ownName, $ownPath);
        self::assertNotNull($own, $ownName);

        $jar->clear();
        $jar->set(new Cookie($name, $own->getValue(), null, $path));
    }

    /**
     * A refusal alone also passes when the cookie never arrived. The other firewall's token in the request's
     * session shows the area opened the logged-in session and still said no.
     */
    private function assertRequestReadTheSessionOf(string $firewall): void
    {
        self::assertTrue($this->client->getRequest()->getSession()->has('_security_'.$firewall), $firewall);
    }

    private function logOutOf(string $path): void
    {
        $page = $this->client->request('GET', $path);
        $this->client->submit($page->filter('.sidebar__logout-form')->form());
    }

    private function assertOpens(string $path): void
    {
        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful($path);
    }

    private function assertTurnedAwayTo(string $login, string $path): void
    {
        $this->client->request('GET', $path);

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode(), $path);
        self::assertSame($login, str_replace('http://localhost', '', (string) $response->headers->get('Location')), $path);
    }
}
