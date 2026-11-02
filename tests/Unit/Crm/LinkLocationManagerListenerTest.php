<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Command\LinkLocationManager;
use App\Crm\EventListener\LinkLocationManagerListener;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Portal\Event\LocationManagerConfirmed;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class LinkLocationManagerListenerTest extends TestCase
{
    public function test_it_links_without_a_company_command_when_the_venue_has_a_company(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };

        (new LinkLocationManagerListener($bus, $this->companies(), new NullLogger()))($this->event());

        self::assertEquals(
            [new LinkLocationManager('venue-1', 'Schloss Seeblick', 'anna@example.ch', 'Anna', 'Meier', '044 123 45 67', 'activity.location_manager')],
            $bus->messages,
        );
    }

    /**
     * The text the app's default logger writes, which is the message with its placeholders filled.
     */
    #[DataProvider('failures')]
    public function test_a_failure_never_escapes_and_is_logged_without_its_message(\Throwable $failure, string $class): void
    {
        $bus = new class($failure) implements MessageBusInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw $this->failure;
            }
        };
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        (new LinkLocationManagerListener($bus, $this->companies(), new Logger(LogLevel::ERROR, $output)))($this->event());

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: linking the location manager to the CRM failed ('.$class.' at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
    }

    /**
     * @return iterable<string, array{\Throwable, class-string}>
     */
    public static function failures(): iterable
    {
        $wrapped = new \DomainException('Duplicate key: anna@example.ch.');
        $command = new LinkLocationManager('venue-1', 'Schloss Seeblick', 'anna@example.ch', 'Anna', 'Meier', null, 'activity.location_manager');

        yield 'an exception' => [new \RuntimeException('The CRM is down, anna@example.ch.'), \RuntimeException::class];
        yield 'an error' => [new \TypeError('anna@example.ch is not a string.'), \TypeError::class];
        yield 'a handler failure, unwrapped' => [new HandlerFailedException(new Envelope($command), [$wrapped]), \DomainException::class];
    }

    /**
     * The real query on a stubbed connection, because the class is final: the venue has company `company-1`.
     */
    private function companies(): VenueCompanyQuery
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('company-1');

        return new VenueCompanyQuery($connection);
    }

    private function event(): LocationManagerConfirmed
    {
        return new LocationManagerConfirmed('venue-1', 'Schloss Seeblick', 'anna@example.ch', 'Anna', 'Meier', '044 123 45 67');
    }
}
