<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\CreateProRequestTask;
use App\Crm\EventListener\CreateProTaskListener;
use App\Crm\ProRequestTitle;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Portal\Event\ProRequested;
use App\Shared\Translation\EnabledLocale;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Pro anfragen" reaches the CRM here (F12): a task on the venue's company, the company made first when the venue has
 * none, nothing dispatched for a request that has an open task already, and a failure that never reaches the manager.
 */
final class CreateProTaskListenerTest extends TestCase
{
    public function test_it_writes_the_task_on_the_company_of_the_venue(): void
    {
        $bus = $this->recordingBus();

        $this->listener($bus, $this->companies('company-1'))($this->event());

        self::assertCount(1, $bus->messages);
        self::assertInstanceOf(CreateProRequestTask::class, $bus->messages[0]);
        self::assertSame('company-1', $bus->messages[0]->companyId);
        self::assertSame('Schloss Seeblick', $bus->messages[0]->venueName);
        self::assertSame('anna@example.ch', $bus->messages[0]->email);
    }

    public function test_without_a_company_it_creates_one_and_writes_the_task_on_it(): void
    {
        $bus = $this->recordingBus();

        $this->listener($bus, $this->companies(false))($this->event());

        self::assertCount(2, $bus->messages);
        self::assertInstanceOf(CreateCompanyForVenue::class, $bus->messages[0]);
        self::assertSame('venue-1', $bus->messages[0]->venueId);
        self::assertInstanceOf(CreateProRequestTask::class, $bus->messages[1]);
        self::assertSame($bus->messages[0]->companyId, $bus->messages[1]->companyId);
    }

    public function test_a_company_with_an_open_task_of_this_title_gets_nothing(): void
    {
        $bus = $this->recordingBus();

        $this->listener($bus, $this->companies('company-1', openTask: true))($this->event());

        self::assertSame([], $bus->messages);
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

        (new CreateProTaskListener($bus, $this->companies('company-1'), $this->titles(), new Logger(LogLevel::ERROR, $output)))($this->event());

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Venue venue-1: writing the Pro request task failed ('.$class.' at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
        self::assertStringNotContainsString('Seeblick', $line);
    }

    /**
     * @return iterable<string, array{\Throwable, class-string}>
     */
    public static function failures(): iterable
    {
        $wrapped = new \DomainException('Duplicate key: anna@example.ch.');

        yield 'an exception' => [new \RuntimeException('The CRM is down, anna@example.ch.'), \RuntimeException::class];
        yield 'a handler failure, unwrapped' => [new HandlerFailedException(new Envelope(new CreateProRequestTask('task-1', 'company-1', 'Schloss Seeblick', 'anna@example.ch')), [$wrapped]), \DomainException::class];
    }

    private function event(): ProRequested
    {
        return new ProRequested('venue-1', 'Schloss Seeblick', 'anna@example.ch', 'Anna', 'Meier');
    }

    private function listener(MessageBusInterface $bus, VenueCompanyQuery $companies): CreateProTaskListener
    {
        return new CreateProTaskListener($bus, $companies, $this->titles(), new NullLogger());
    }

    /**
     * The real service over a translator that fills the placeholders, because the class is final; the title's own text
     * is `ProRequestTitleTest`'s.
     */
    private function titles(): ProRequestTitle
    {
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'Pro-Anfrage: '.(string) $parameters['venue'].' ('.(string) $parameters['email'].')';
            }

            public function getLocale(): string
            {
                return 'de';
            }
        };

        return new ProRequestTitle($translator, new EnabledLocale(['de'], 'de'));
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
     * The real query on a stubbed connection, because the class is final: the company's id answers the company lookup,
     * `$openTask` the task check.
     */
    private function companies(string|false $companyId, bool $openTask = false): VenueCompanyQuery
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql): string|bool => str_contains($sql, 'crm_tasks') ? $openTask : $companyId,
        );

        return new VenueCompanyQuery($connection);
    }
}
