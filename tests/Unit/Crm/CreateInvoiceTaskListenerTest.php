<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\CreateInvoiceTask;
use App\Crm\EventListener\CreateInvoiceTaskListener;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Venues\Event\NewVenuePublished;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class CreateInvoiceTaskListenerTest extends TestCase
{
    public function test_it_writes_the_task_on_the_company_of_the_venue(): void
    {
        $bus = $this->recordingBus();

        (new CreateInvoiceTaskListener($bus, $this->companies('company-1'), new NullLogger()))(new NewVenuePublished('venue-1'));

        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(CreateInvoiceTask::class, $bus->messages[0]);
        self::assertSame('company-1', $bus->messages[0]->companyId);
    }

    public function test_without_a_company_it_creates_one_and_writes_the_task_on_it(): void
    {
        $bus = $this->recordingBus();

        (new CreateInvoiceTaskListener($bus, $this->companies(false), new NullLogger()))(new NewVenuePublished('venue-1'));

        self::assertCount(2, $bus->messages);
        self::assertInstanceOf(CreateCompanyForVenue::class, $bus->messages[0]);
        self::assertSame('venue-1', $bus->messages[0]->venueId);
        self::assertInstanceOf(CreateInvoiceTask::class, $bus->messages[1]);
        self::assertSame($bus->messages[0]->companyId, $bus->messages[1]->companyId);
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

        (new CreateInvoiceTaskListener($bus, $this->companies('company-1'), new Logger(LogLevel::ERROR, $output)))(new NewVenuePublished('venue-1'));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: writing the invoice task failed ('.$class.' at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
    }

    /**
     * @return iterable<string, array{\Throwable, class-string}>
     */
    public static function failures(): iterable
    {
        $wrapped = new \DomainException('Duplicate key: anna@example.ch.');

        yield 'an exception' => [new \RuntimeException('The CRM is down, anna@example.ch.'), \RuntimeException::class];
        yield 'a handler failure, unwrapped' => [new HandlerFailedException(new Envelope(new CreateInvoiceTask('task-1', 'company-1')), [$wrapped]), \DomainException::class];
    }

    /**
     * @return MessageBusInterface&object{messages: list<object>}
     */
    private function recordingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }

    /**
     * The real query on a stubbed connection, because the class is final.
     */
    private function companies(string|false $companyId): VenueCompanyQuery
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($companyId);

        return new VenueCompanyQuery($connection);
    }
}
