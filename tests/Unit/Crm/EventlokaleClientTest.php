<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\EventlokaleClient;
use App\Tests\Support\Crm\EventlokalePayloads;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

final class EventlokaleClientTest extends TestCase
{
    private const string BASE = 'https://www.eventlokale.ch';

    public function test_search_posts_one_event_category_with_the_page_and_the_clock_time(): void
    {
        $response = new JsonMockResponse(['data' => ['data' => [], 'total' => 0, 'last_page' => 1]]);
        $now = (new MockClock('2026-11-03 10:00:00', 'Europe/Zurich'))->now();

        $body = $this->client($response)->search(371, 3, $now);

        self::assertSame(['data' => ['data' => [], 'total' => 0, 'last_page' => 1]], $body);
        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame(self::BASE.'/api/guest/location/search/draw', $response->getRequestUrl());
        $sent = $response->getRequestOptions()['body'];
        self::assertIsString($sent);
        $expected = <<<'JSON'
            {"category":1,"column":0,"dir":"desc","draw":1,"page":3,"length":200,
             "search":{"active":"location","service":"","events":[371],"region":"","term":"","persons":0,"name":"","place":"","distance":0,
               "reachability":"","room_number":null,"room_area":null,"room_height":null,"buildings":[],"locations":[],"infrastructures":[],
               "technologies":[],"furniture":[],"surroundings":[],"prices":[],"styles":[],"operations":[],"offers":[],"mapCoordinates":{},
               "secondary_data":{"eventCategory":"default","dayCount":1,"days":{"d1":null,"d2":null,"d3":null,"d4":null},
                 "meals":{"d1":null,"d2":null,"d3":null,"d4":null},"overnight":{"n1":[0,0],"n2":[0,0],"n3":[0,0]},"startDate":null,
                 "startDateDefault":"2026-11-03T10:00:00+01:00","exclusive":null,"additionalRooms":[],"rentEntireLocation":false,"location_id":null,
                 "noCateringRequired":false},
               "seedId":"61496132"}}
            JSON;
        // Objects, not arrays, so `{}` and `[]` stay apart.
        self::assertEquals(json_decode($expected, false, 512, \JSON_THROW_ON_ERROR), json_decode($sent, false, 512, \JSON_THROW_ON_ERROR));
    }

    public function test_detail_gets_the_venue_and_decodes_a_json_body_served_as_html(): void
    {
        $response = new MockResponse('{"general_data":{"full_name":"Testschloss"}}', [
            'response_headers' => ['content-type' => 'text/html; charset=UTF-8'],
        ]);

        $detail = $this->client($response)->detail(7);

        self::assertSame(['general_data' => ['full_name' => 'Testschloss']], $detail);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::BASE.'/api/guest/location/7/get', $response->getRequestUrl());
    }

    public function test_detail_is_null_when_the_venue_is_gone(): void
    {
        self::assertNull($this->client(new MockResponse('', ['http_code' => 404]))->detail(7));
        self::assertNull($this->client(new MockResponse('', ['http_code' => 410]))->detail(7));
    }

    public function test_detail_throws_on_a_server_error(): void
    {
        $this->expectException(ServerExceptionInterface::class);

        $this->client(new MockResponse('', ['http_code' => 500]))->detail(7);
    }

    public function test_taxonomies_gets_every_term_group(): void
    {
        $response = new JsonMockResponse(['taxonomies' => ['buildings' => []]]);

        self::assertSame(['taxonomies' => ['buildings' => []]], $this->client($response)->taxonomies());
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame(self::BASE.'/api/guest/location/all-taxonomies', $response->getRequestUrl());
    }

    public function test_the_event_categories_are_the_suitable_for_group(): void
    {
        self::assertSame([370, 371], EventlokaleClient::eventIds(EventlokalePayloads::taxonomies()));
        self::assertSame([], EventlokaleClient::eventIds(['taxonomies' => ['buildings' => []]]));
        self::assertSame([], EventlokaleClient::eventIds([]));
    }

    private function client(MockResponse $response): EventlokaleClient
    {
        return new EventlokaleClient(new MockHttpClient($response, self::BASE));
    }
}
