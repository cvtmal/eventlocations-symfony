<?php

declare(strict_types=1);

namespace App\Tests\Application\Admin;

use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

final class DashboardTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    public function test_it_greets_by_first_name_with_the_long_german_date(): void
    {
        self::mockTime('2026-10-29 10:00:00 UTC');
        $this->logInAs(displayName: 'Andrea Keller');

        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('h1', 'Guten Tag, Andrea');
        self::assertSelectorTextSame('.dashboard__date', 'Donnerstag, 29. Oktober 2026');
    }

    public function test_the_date_is_the_zurich_one(): void
    {
        self::mockTime('2026-10-30 23:30:00 UTC');
        $this->logInAs();

        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('.dashboard__date', 'Samstag, 31. Oktober 2026');
    }

    public function test_without_widgets_it_shows_the_empty_state(): void
    {
        $this->logInAs();

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('.empty__title', 'Noch keine Inhalte');
    }
}
