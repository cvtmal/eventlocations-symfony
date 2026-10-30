<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

final class IdleTimeoutTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    public function test_a_session_survives_just_under_two_hours(): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00 UTC');
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(7199);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_activity_restarts_the_timer(): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00 UTC');
        $this->logInAs();
        $this->client->request('GET', '/admin');

        $clock->sleep(7000);
        $this->client->request('GET', '/admin');
        $clock->sleep(7000);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_a_session_idle_for_two_hours_ends(): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00 UTC');
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(7201);
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Du warst zwei Stunden inaktiv.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodedUrls(): iterable
    {
        foreach (self::encodedSpellings('/admin/benutzer') as $spelling => $url) {
            yield $spelling => [$url];
        }
        foreach (self::encodedSpellings('/admin') as $spelling => $url) {
            yield $spelling.', dashboard' => [$url];
        }
    }

    #[DataProvider('encodedUrls')]
    public function test_a_session_idle_for_two_hours_ends_on_an_encoded_url_too(string $url): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00 UTC');
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(7201);
        $this->sendSessionCookieEverywhere();
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/admin/login');
    }

    #[DataProvider('encodedUrls')]
    public function test_an_encoded_url_counts_as_activity(string $url): void
    {
        $clock = self::mockTime('2026-10-30 12:00:00 UTC');
        $this->logInAs();
        $this->client->request('GET', '/admin');

        $clock->sleep(7000);
        $this->sendSessionCookieEverywhere();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $clock->sleep(7000);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }
}
