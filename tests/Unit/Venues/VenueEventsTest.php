<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\Event\NewVenuePublished;
use App\Venues\Event\VenueEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;

final class VenueEventsTest extends TestCase
{
    public function test_it_dispatches_the_venue_id(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(NewVenuePublished::class, static function (NewVenuePublished $event) use (&$seen): void {
            $seen[] = $event->venueId;
        });

        (new VenueEvents($dispatcher, new Logger(LogLevel::ERROR, 'php://memory')))->newVenuePublished('venue-1');

        self::assertSame(['venue-1'], $seen);
    }

    /**
     * The text the app's default logger writes, which is the message with its placeholders filled.
     */
    public function test_a_throwing_subscriber_is_logged_and_never_reaches_the_publish(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(NewVenuePublished::class, static function (): never {
            throw new \RuntimeException('The CRM is down, anna@example.ch.');
        });
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        (new VenueEvents($dispatcher, new Logger(LogLevel::ERROR, $output)))->newVenuePublished('venue-1');

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: a NewVenuePublished subscriber failed (RuntimeException at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
    }
}
