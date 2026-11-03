<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Admin\EventListener\IdleTimeoutListener;
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

    /** A literal, so moving the constant cannot pass by moving these tests with it. */
    public function test_the_idle_limit_is_thirty_days(): void
    {
        self::assertSame(2_592_000, IdleTimeoutListener::IDLE_SECONDS);
    }

    public function test_a_session_survives_just_under_thirty_days(): void
    {
        $clock = self::mockTime();
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(IdleTimeoutListener::IDLE_SECONDS - 1);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_activity_restarts_the_timer(): void
    {
        $clock = self::mockTime();
        $this->logInAs();
        $this->client->request('GET', '/admin');

        $clock->sleep(2_000_000);
        $this->client->request('GET', '/admin');
        $clock->sleep(2_000_000);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_a_session_idle_for_thirty_days_ends(): void
    {
        $clock = self::mockTime();
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(IdleTimeoutListener::IDLE_SECONDS + 1);
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Du warst 30 Tage inaktiv.');
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
    public function test_a_session_idle_for_thirty_days_ends_on_an_encoded_url_too(string $url): void
    {
        $clock = self::mockTime();
        $this->logInAs();
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $clock->sleep(IdleTimeoutListener::IDLE_SECONDS + 1);
        $this->sendSessionCookieEverywhere();
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/admin/login');
    }

    #[DataProvider('encodedUrls')]
    public function test_an_encoded_url_counts_as_activity(string $url): void
    {
        $clock = self::mockTime();
        $this->logInAs();
        $this->client->request('GET', '/admin');

        $clock->sleep(2_000_000);
        $this->sendSessionCookieEverywhere();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $clock->sleep(2_000_000);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }
}
