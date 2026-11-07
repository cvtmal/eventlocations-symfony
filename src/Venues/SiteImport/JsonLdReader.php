<?php

declare(strict_types=1);

namespace App\Venues\SiteImport;

use App\Shared\Exception\FetchRefused;
use App\Shared\Http\SafeUrl;
use Symfony\Component\DomCrawler\Crawler;

/**
 * schema.org JSON-LD blocks of a page. Only nodes of a business or place type count (a venue's site also carries
 * breadcrumbs, search boxes and articles); what they say is read with high confidence.
 */
final class JsonLdReader
{
    private const array TYPES = [
        'LocalBusiness', 'EventVenue', 'Hotel', 'Resort', 'Hostel', 'Motel', 'BedAndBreakfast', 'LodgingBusiness',
        'Restaurant', 'FoodEstablishment', 'BarOrPub', 'CafeOrCoffeeShop', 'Winery', 'Brewery', 'Place', 'Organization',
        'Corporation', 'CivicStructure', 'PerformingArtsTheater', 'Museum', 'ArtGallery', 'TouristAttraction',
        'LandmarksOrHistoricalBuildings', 'SportsActivityLocation', 'HealthClub', 'Campground',
    ];
    private const int MAX_BLOCKS = 12;
    private const int MAX_NODES = 60;

    /**
     * @return list<string> the raw image references of the matching nodes
     */
    public function read(Crawler $page, string $sourceUrl, FactSet $facts): array
    {
        $images = [];
        $blocks = 0;
        foreach (DomElements::of($page->filter('script[type]'), 60) as $script) {
            if (1 !== preg_match('~^\s*application/ld\+json~i', $script->getAttribute('type')) || ++$blocks > self::MAX_BLOCKS) {
                continue;
            }
            $data = json_decode(trim($script->textContent), true, 32);
            if (!\is_array($data)) {
                continue;
            }

            $nodes = [];
            $this->collect($data, $nodes);
            foreach ($nodes as $node) {
                $types = array_values(array_filter((array) ($node['@type'] ?? []), 'is_string'));
                if ([] === array_intersect($types, self::TYPES)) {
                    continue;
                }
                $images = [...$images, ...$this->node($node, $types, $sourceUrl, $facts)];
            }
        }

        return $images;
    }

    /**
     * Flattens lists, @graph and mainEntity into one list of objects.
     *
     * @param array<mixed>       $data
     * @param list<array<mixed>> $nodes
     */
    private function collect(array $data, array &$nodes): void
    {
        if (\count($nodes) >= self::MAX_NODES) {
            return;
        }
        if (array_is_list($data)) {
            foreach ($data as $item) {
                if (\is_array($item)) {
                    $this->collect($item, $nodes);
                }
            }

            return;
        }

        $nodes[] = $data;
        foreach (['@graph', 'mainEntity'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                $this->collect($data[$key], $nodes);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $types
     *
     * @return list<string>
     */
    private function node(array $node, array $types, string $sourceUrl, FactSet $facts): array
    {
        foreach ($types as $type) {
            $facts->add('type', $type, 0.9, $sourceUrl, 'json_ld');
        }
        $text = static fn (mixed $value, int $max = 300): ?string => \is_string($value) && '' !== trim($value)
            ? mb_substr(trim(html_entity_decode(strip_tags($value))), 0, $max)
            : null;

        if (null !== $name = $text($node['name'] ?? null, 200)) {
            $facts->add('name', $name, 0.9, $sourceUrl, 'json_ld');
        }
        if (null !== $description = $text($node['description'] ?? null, 600)) {
            $facts->add('description', $description, 0.7, $sourceUrl, 'json_ld');
        }
        if (null !== $url = $text($node['url'] ?? null, 300)) {
            try {
                $facts->add('website', (string) SafeUrl::parse($url), 0.7, $sourceUrl, 'json_ld');
            } catch (FetchRefused) {
                // not a public http(s) address: no fact, so nothing downstream can mistake it for one
            }
        }
        if (null !== $phone = $text($node['telephone'] ?? null, 40)) {
            (new ContactReader())->phone($phone, 0.9, 'json_ld', $sourceUrl, $facts);
        }
        if (null !== $email = $text($node['email'] ?? null, 120)) {
            (new ContactReader())->email((string) preg_replace('/^mailto:/i', '', $email), 0.9, 'json_ld', $sourceUrl, $facts);
        }

        $address = $node['address'] ?? null;
        if (\is_array($address) && array_is_list($address)) {
            $address = $address[0] ?? null;
        }
        if (\is_array($address)) {
            $country = $address['addressCountry'] ?? null;
            $country = \is_array($country) ? ($country['name'] ?? null) : $country;
            $parts = [
                'street' => $text($address['streetAddress'] ?? null, 120),
                'postal_code' => $text($address['postalCode'] ?? null, 20),
                'locality' => $text($address['addressLocality'] ?? null, 80),
                'country' => $text($country, 60),
            ];
            // An address object with every part empty (some SEO plugins write one) says nothing.
            if ([] !== array_filter($parts, static fn (?string $part): bool => null !== $part)) {
                $facts->add('address', $parts, 0.9, $sourceUrl, 'json_ld');
            }
        }

        $geo = $node['geo'] ?? null;
        if (\is_array($geo) && is_numeric($geo['latitude'] ?? null) && is_numeric($geo['longitude'] ?? null)) {
            $lat = (float) $geo['latitude'];
            $lng = (float) $geo['longitude'];
            if (is_finite($lat) && is_finite($lng) && abs($lat) <= 90.0 && abs($lng) <= 180.0) {
                $facts->add('geo', ['lat' => round($lat, 6), 'lng' => round($lng, 6)], 0.9, $sourceUrl, 'json_ld');
            }
        }
        if (is_numeric($node['maximumAttendeeCapacity'] ?? null) && (float) $node['maximumAttendeeCapacity'] >= 1.0 && (float) $node['maximumAttendeeCapacity'] <= 100000.0) {
            $facts->add('capacity', (int) $node['maximumAttendeeCapacity'], 0.8, $sourceUrl, 'json_ld');
        }

        return $this->imageUrls($node['image'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function imageUrls(mixed $image): array
    {
        if (\is_string($image)) {
            return [$image];
        }
        if (!\is_array($image)) {
            return [];
        }
        if (isset($image['url']) || isset($image['contentUrl'])) {
            $url = $image['url'] ?? $image['contentUrl'];

            return \is_string($url) ? [$url] : [];
        }

        $urls = [];
        foreach (\array_slice($image, 0, 20) as $item) {
            $urls = [...$urls, ...$this->imageUrls($item)];
        }

        return $urls;
    }
}
