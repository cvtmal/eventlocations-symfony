<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\Event\PortalEvents;
use App\Portal\Event\ProRequested;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * The publication of "Pro anfragen" (F12): the event reaches its subscribers as it was built, and a subscriber that
 * throws is logged without the manager's details and cannot fail the press.
 */
final class PortalEventsProRequestedTest extends TestCase
{
    public function test_the_event_reaches_its_subscribers_as_built(): void
    {
        $seen = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ProRequested::class, static function (ProRequested $event) use (&$seen): void {
            $seen[] = $event;
        });
        $event = new ProRequested('venue-1', 'Schloss Seeblick', 'nora@example.ch', 'Nora', 'Meier');

        new PortalEvents($dispatcher, new Logger(LogLevel::ERROR, fopen('php://memory', 'w+') ?: null))->proRequested($event);

        self::assertSame([$event], $seen);
    }

    public function test_a_throwing_subscriber_is_logged_without_details_and_stops_nothing(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ProRequested::class, static function (): never {
            throw new \RuntimeException('The CRM is down, nora@example.ch.');
        });
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        new PortalEvents($dispatcher, new Logger(LogLevel::ERROR, $output))->proRequested(
            new ProRequested('venue-1', 'Schloss Seeblick', 'nora@example.ch', 'Nora', 'Meier'),
        );

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: a ProRequested subscriber failed (RuntimeException at '.__FILE__.':', $line);
        self::assertStringNotContainsString('nora', $line);
        self::assertStringNotContainsString('Seeblick', $line);
    }
}
