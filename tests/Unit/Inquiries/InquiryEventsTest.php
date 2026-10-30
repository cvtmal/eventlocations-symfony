<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries;

use App\Inquiries\Event\InquiryEvents;
use App\Inquiries\Event\InquirySubmitted;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\Log\Logger;

final class InquiryEventsTest extends TestCase
{
    public function test_subscribers_get_the_inquiry_id(): void
    {
        $received = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(InquirySubmitted::class, static function (InquirySubmitted $event) use (&$received): void {
            $received[] = $event->inquiryId;
        });

        (new InquiryEvents($dispatcher, new NullLogger()))->submitted('id-1');

        self::assertSame(['id-1'], $received);
    }

    /**
     * The text the app's default logger writes, which is the message with its placeholders filled.
     */
    public function test_a_throwing_subscriber_is_logged_and_never_reaches_the_caller(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(InquirySubmitted::class, static function (): never {
            throw new \RuntimeException('The CRM is down, anna@example.ch.');
        });
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        (new InquiryEvents($dispatcher, new Logger(LogLevel::ERROR, $output)))->submitted('id-1');

        $line = (string) stream_get_contents($output, offset: 0);
        self::assertStringContainsString('[error] Inquiry id-1: an InquirySubmitted subscriber failed (RuntimeException at '.__FILE__.':', $line);
        self::assertStringNotContainsString('anna', $line);
    }
}
