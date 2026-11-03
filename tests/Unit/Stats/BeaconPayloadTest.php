<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\BeaconPayload;
use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\PageType;
use App\Stats\ValueObject\SpoolEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BeaconPayloadTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef';

    public function test_a_view_on_a_detail_page_carries_its_venue(): void
    {
        [$event] = self::parse(['p' => 'detail', 'v' => '46', 'u' => '/hochzeitslocation/x', 'e' => ['view']]);

        self::assertSame(EventType::PageView, $event->type);
        self::assertSame([46], $event->venues);
        self::assertSame(PageType::Detail, $event->page);
        self::assertSame('/hochzeitslocation/x', $event->path);
        self::assertSame('Mozilla/5.0', $event->userAgent);
        self::assertSame(self::HASH, $event->visitorHash);
        self::assertFalse($event->automated);
        self::assertEquals(new \DateTimeImmutable('2026-11-03T12:00:00+01:00'), $event->at);
    }

    public function test_a_view_off_the_detail_page_has_no_venue(): void
    {
        [$event] = self::parse(['p' => 'listing', 'v' => '46', 'e' => ['view']]);

        self::assertNull($event->venues);
    }

    public function test_an_unknown_or_missing_page_becomes_other(): void
    {
        self::assertSame(PageType::Other, self::parse(['p' => 'admin', 'e' => ['view']])[0]->page);
        self::assertSame(PageType::Other, self::parse(['e' => ['view']])[0]->page);
        self::assertSame(PageType::Other, self::parse(['p' => ['home'], 'e' => ['view']])[0]->page);
    }

    public function test_a_bad_venue_is_dropped_and_a_click_without_one_too(): void
    {
        foreach (['0', '046', 'abc', '2147483648', '12345678901', '-1'] as $venue) {
            $events = self::parse(['p' => 'detail', 'v' => $venue, 'e' => ['view', 'click:website']]);

            self::assertCount(1, $events, $venue);
            self::assertSame(EventType::PageView, $events[0]->type);
            self::assertNull($events[0]->venues, $venue);
        }
    }

    public function test_a_click_carries_its_kind_and_the_venue(): void
    {
        $events = self::parse(['p' => 'detail', 'v' => '46', 'e' => ['click:phone', 'click:instagram', 'click:fax', 'click:12', 'click']]);

        self::assertSame([ClickKind::Phone, ClickKind::Instagram], array_map(static fn (SpoolEvent $event): ?ClickKind => $event->kind, $events));
        self::assertSame([[46], [46]], array_map(static fn (SpoolEvent $event): ?array => $event->venues, $events));
    }

    public function test_an_add_carries_its_number(): void
    {
        $events = self::parse(['p' => 'listing', 'e' => ['add:7', 'add:0', 'add:7.8', 'add:x', 'add']]);

        self::assertCount(1, $events);
        self::assertSame(EventType::MerklisteAdd, $events[0]->type);
        self::assertSame([7], $events[0]->venues);
    }

    public function test_an_impression_is_deduplicated_and_capped_at_100_numbers(): void
    {
        [$event] = self::parse(['p' => 'listing', 'e' => ['imp:3.1.3.0.2']]);
        self::assertSame(EventType::Impression, $event->type);
        self::assertSame([3, 1, 2], $event->venues);

        self::assertCount(1, self::parse(['e' => ['imp:'.implode('.', range(1, 100))]]));
        self::assertSame([], self::parse(['e' => ['imp:'.implode('.', range(1, 101))]]));
        self::assertSame([], self::parse(['e' => ['imp:0', 'imp']]));
    }

    public function test_events_past_the_50th_and_malformed_ones_are_dropped(): void
    {
        self::assertCount(50, self::parse(['e' => array_fill(0, 51, 'view')]));
        self::assertCount(1, self::parse(['e' => ['view', 'view:1', 'VIEW', 'scroll', ' view', ['view'], 'view' => 'x']]));
        self::assertSame([], self::parse(['e' => 'view']));
        self::assertSame([], self::parse(['e' => []]));
        self::assertSame([], self::parse([]));
    }

    public function test_the_referrer_is_cut_to_its_host_and_our_own_host_is_none(): void
    {
        self::assertSame('www.google.com', self::parse(['r' => 'https://WWW.Google.com/search?q=hochzeit', 'e' => ['view']])[0]->referrerHost);
        self::assertNull(self::parse(['r' => 'https://www.hochzeitslocation.ch/hochzeitslocations', 'e' => ['view']])[0]->referrerHost);
        self::assertNull(self::parse(['r' => 'not a url', 'e' => ['view']])[0]->referrerHost);
        self::assertNull(self::parse(['e' => ['view']])[0]->referrerHost);
    }

    public function test_webdriver_marks_the_events_automated(): void
    {
        self::assertTrue(self::parse(['w' => '1', 'e' => ['view']])[0]->automated);
        self::assertFalse(self::parse(['w' => '0', 'e' => ['view']])[0]->automated);
    }

    public function test_texts_are_cut_to_255_valid_characters(): void
    {
        [$event] = self::parse(['u' => '/'.str_repeat('ä', 300)."\0", 'e' => ['view']], "Mozilla\xff/".str_repeat('x', 300));

        self::assertSame(255, mb_strlen((string) $event->path));
        self::assertSame(255, mb_strlen((string) $event->userAgent));
        self::assertTrue(mb_check_encoding((string) $event->userAgent, 'UTF-8'));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<SpoolEvent>
     */
    private static function parse(array $body, string $userAgent = 'Mozilla/5.0'): array
    {
        $request = Request::create('https://www.hochzeitslocation.ch/_stats', 'POST', $body, server: ['HTTP_USER_AGENT' => $userAgent]);

        return BeaconPayload::fromRequest($request, new \DateTimeImmutable('2026-11-03T12:00:00+01:00'), self::HASH);
    }
}
