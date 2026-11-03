<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Portal\EventListener\PortalIdleTimeoutListener;
use App\Tests\Support\Portal\PortalSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * /konto keeps the 2 hours it had before the shared session store moved to 30 days for the back office. The numbers
 * are literal so that they pin the value.
 */
final class PortalIdleTimeoutTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;
    use PortalSession;

    public function test_the_idle_limit_stays_two_hours(): void
    {
        self::assertSame(7200, PortalIdleTimeoutListener::IDLE_SECONDS);
    }

    public function test_a_session_survives_just_under_two_hours(): void
    {
        $clock = self::mockTime();
        $this->logInAsManager();
        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();

        $clock->sleep(7199);
        $this->client->request('GET', '/konto/profil');

        self::assertResponseIsSuccessful();
    }

    public function test_activity_restarts_the_timer(): void
    {
        $clock = self::mockTime();
        $this->logInAsManager();
        $this->client->request('GET', '/konto/profil');

        $clock->sleep(7000);
        $this->client->request('GET', '/konto/profil');
        $clock->sleep(7000);
        $this->client->request('GET', '/konto/profil');

        self::assertResponseIsSuccessful();
    }

    public function test_a_session_idle_for_two_hours_ends(): void
    {
        $clock = self::mockTime();
        $this->logInAsManager();
        $this->client->request('GET', '/konto/profil');
        self::assertResponseIsSuccessful();

        $clock->sleep(7201);
        $this->client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');

        $this->client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');
    }
}
