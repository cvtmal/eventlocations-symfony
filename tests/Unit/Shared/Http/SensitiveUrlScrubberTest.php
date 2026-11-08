<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Http;

use App\Shared\Http\SensitiveUrlScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

final class SensitiveUrlScrubberTest extends TestCase
{
    private const string TOKEN = 'AZpcPn0Qeit8TV5vcIGSo4x1Q2bJk0LpZ9qWcA7d_-Xy';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'a preview link' => ['https://deine-eventlocation.ch/vorschau/'.self::TOKEN, 'https://deine-eventlocation.ch/vorschau/[Filtered]'];
        yield 'with a query and a fragment' => ['https://deine-eventlocation.ch/vorschau/'.self::TOKEN.'?a=1#bilder', 'https://deine-eventlocation.ch/vorschau/[Filtered]#bilder'];
        yield 'a path' => ['/vorschau/'.self::TOKEN, '/vorschau/[Filtered]'];
        yield 'a cancellation link (G9)' => ['https://deine-eventlocation.ch/konto/abo-kuendigen/'.self::TOKEN, 'https://deine-eventlocation.ch/konto/abo-kuendigen/[Filtered]'];
        yield 'another page' => ['https://deine-eventlocation.ch/eventlocations?seite=2', 'https://deine-eventlocation.ch/eventlocations?seite=2'];
        yield 'the staff preview' => ['https://deine-eventlocation.ch/admin/locations/12/vorschau', 'https://deine-eventlocation.ch/admin/locations/12/vorschau'];
    }

    #[DataProvider('urls')]
    public function test_the_secret_segment_goes(string $url, string $scrubbed): void
    {
        self::assertSame($scrubbed, SensitiveUrlScrubber::scrub($url));
    }

    public function test_an_error_report_keeps_everything_but_the_token(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://deine-eventlocation.ch/vorschau/'.self::TOKEN, 'method' => 'GET']);

        $sent = new SensitiveUrlScrubber()($event);

        self::assertSame(['url' => 'https://deine-eventlocation.ch/vorschau/[Filtered]', 'method' => 'GET'], $sent->getRequest());
    }
}
