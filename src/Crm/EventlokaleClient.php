<?php

declare(strict_types=1);

namespace App\Crm;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * eventlokale.ch's internal JSON API, the one its own front end calls (#49). Only paths under
 * /api/guest/ are called, which robots.txt allows. One call is one request: pacing is
 * EventlokaleCrawl's job, retries are the scoped client's.
 */
final class EventlokaleClient
{
    public const string BASE_URL = 'https://www.eventlokale.ch';
    /** A fixed seed keeps the shuffled order stable across pages. */
    public const string SEED = '61496132';
    public const int CATEGORY_SWITZERLAND = 1;
    public const int PAGE_LENGTH = 200;

    public function __construct(
        private readonly HttpClientInterface $eventlokaleClient,
    ) {
    }

    /**
     * One page of the search for one event category (F14: the crawl lists every category in turn, one id per
     * request, the shape the listing page sends), the paginator envelope as served.
     *
     * @return array<mixed>
     *
     * @throws ExceptionInterface
     */
    public function search(int $eventId, int $page, \DateTimeImmutable $now): array
    {
        return $this->eventlokaleClient->request('POST', '/api/guest/location/search/draw', [
            'json' => self::searchBody($eventId, $page, $now),
        ])->toArray();
    }

    /**
     * The ids of the event categories (the `suitable_for` group of `all-taxonomies`), in the served order.
     *
     * @param array<mixed> $taxonomies
     *
     * @return list<int>
     */
    public static function eventIds(array $taxonomies): array
    {
        $group = $taxonomies['taxonomies']['suitable_for'] ?? null;
        $ids = [];
        foreach (\is_array($group) ? $group : [] as $term) {
            if (\is_array($term) && \is_int($term['id'] ?? null)) {
                $ids[$term['id']] = $term['id'];
            }
        }

        return array_values($ids);
    }

    /**
     * A venue's detail; null when the venue is gone (404 or 410). The body comes as text/html
     * although it is JSON, which toArray() decodes all the same.
     *
     * @return array<mixed>|null
     *
     * @throws ExceptionInterface
     */
    public function detail(int $id): ?array
    {
        $response = $this->eventlokaleClient->request('GET', '/api/guest/location/'.$id.'/get');
        if (\in_array($response->getStatusCode(), [404, 410], true)) {
            return null;
        }

        return $response->toArray();
    }

    /**
     * Every term group with its ids and German names.
     *
     * @return array<mixed>
     *
     * @throws ExceptionInterface
     */
    public function taxonomies(): array
    {
        return $this->eventlokaleClient->request('GET', '/api/guest/location/all-taxonomies')->toArray();
    }

    /**
     * The body exactly as the listing page sends it, filtered to one event category.
     *
     * @return array<string, mixed>
     */
    private static function searchBody(int $eventId, int $page, \DateTimeImmutable $now): array
    {
        $days = ['d1' => null, 'd2' => null, 'd3' => null, 'd4' => null];

        return [
            'category' => self::CATEGORY_SWITZERLAND,
            'column' => 0,
            'dir' => 'desc',
            'draw' => 1,
            'page' => $page,
            'length' => self::PAGE_LENGTH,
            'search' => [
                'active' => 'location',
                'service' => '',
                'events' => [$eventId],
                'region' => '',
                'term' => '',
                'persons' => 0,
                'name' => '',
                'place' => '',
                'distance' => 0,
                'reachability' => '',
                'room_number' => null,
                'room_area' => null,
                'room_height' => null,
                'buildings' => [],
                'locations' => [],
                'infrastructures' => [],
                'technologies' => [],
                'furniture' => [],
                'surroundings' => [],
                'prices' => [],
                'styles' => [],
                'operations' => [],
                'offers' => [],
                'mapCoordinates' => new \stdClass(),
                'secondary_data' => [
                    'eventCategory' => 'default',
                    'dayCount' => 1,
                    'days' => $days,
                    'meals' => $days,
                    'overnight' => ['n1' => [0, 0], 'n2' => [0, 0], 'n3' => [0, 0]],
                    'startDate' => null,
                    'startDateDefault' => $now->format(\DATE_ATOM),
                    'exclusive' => null,
                    'additionalRooms' => [],
                    'rentEntireLocation' => false,
                    'location_id' => null,
                    'noCateringRequired' => false,
                ],
                'seedId' => self::SEED,
            ],
        ];
    }
}
