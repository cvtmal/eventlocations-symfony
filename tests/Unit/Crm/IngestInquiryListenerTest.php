<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\Command\IngestInquiry;
use App\Crm\EventListener\IngestInquiryListener;
use App\Inquiries\Event\InquirySubmitted;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Log\Logger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class IngestInquiryListenerTest extends TestCase
{
    public function test_it_dispatches_the_command_for_the_inquiry(): void
    {
        $bus = new class implements MessageBusInterface {
            public ?object $message = null;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->message = $message;

                return new Envelope($message);
            }
        };

        (new IngestInquiryListener($bus, new NullLogger()))(new InquirySubmitted('id-1'));

        self::assertEquals(new IngestInquiry('id-1'), $bus->message);
    }

    /**
     * The text the app's default logger writes, which is the message with its placeholders filled.
     */
    #[DataProvider('failures')]
    public function test_a_failure_never_reaches_the_guest_and_is_logged_without_its_message(\Throwable $failure, string $class): void
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

        (new IngestInquiryListener($bus, new Logger(LogLevel::ERROR, $output)))(new InquirySubmitted('id-1'));

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Inquiry id-1: CRM ingestion failed ('.$class.' at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
    }

    /**
     * @return iterable<string, array{\Throwable, class-string}>
     */
    public static function failures(): iterable
    {
        $wrapped = new \DomainException('Duplicate key: anna@example.ch.');

        yield 'an exception' => [new \RuntimeException('The CRM is down, anna@example.ch.'), \RuntimeException::class];
        yield 'an error' => [new \TypeError('anna@example.ch is not a string.'), \TypeError::class];
        yield 'a handler failure, unwrapped' => [new HandlerFailedException(new Envelope(new IngestInquiry('id-1')), [$wrapped]), \DomainException::class];
    }
}
