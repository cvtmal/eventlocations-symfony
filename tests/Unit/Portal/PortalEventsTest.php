<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\Event\LocationManagerConfirmed;
use App\Portal\Event\PortalEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;

final class PortalEventsTest extends TestCase
{
    /**
     * The text the app's default logger writes, which is the message with its placeholders filled.
     */
    public function test_a_throwing_subscriber_is_logged_and_stops_nothing(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(LocationManagerConfirmed::class, static function (): never {
            throw new \RuntimeException('The CRM is down, nora@example.ch.');
        });
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        (new PortalEvents($dispatcher, new Logger(LogLevel::ERROR, $output)))->managerConfirmed(
            new LocationManagerConfirmed('venue-1', 'Schloss Seeblick', 'nora@example.ch', 'Nora', 'Meier', null),
        );

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: a LocationManagerConfirmed subscriber failed (RuntimeException at '.__FILE__.':', $line);
        self::assertStringNotContainsString('nora', $line);
    }
}
