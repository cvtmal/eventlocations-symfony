<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Geo\ValueObject\Canton;
use App\Search\ReadModel\KnownTerms;
use App\Search\ReadModel\SearchCriteria;
use App\Search\ValueObject\GuestBand;
use App\Venues\ValueObject\Access;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class SearchCriteriaTest extends TestCase
{
    private const TOWN = '0198a3c4-6b1e-7f3a-9c2d-1e4f5a6b7c8d';

    private KnownTerms $known;

    protected function setUp(): void
    {
        $this->known = KnownTerms::of([
            'setting' => ['city', 'lake', 'countryside'],
            'building' => ['castle', 'farm'],
            'infrastructure' => ['air_conditioning', 'heating_ventilation'],
            'furniture' => ['chairs'],
            'technology' => ['projector_screen', 'wifi'],
            'surroundings' => ['garden', 'spa_wellness'],
            'amenity' => ['overnight', 'lake_view', 'tent', 'parking'],
        ]);
    }

    public function test_a_full_state_survives_the_url_round_trip(): void
    {
        $criteria = $this->fromUrl(
            'canton=zh&place='.self::TOWN.'&radius=12&guests=100&q=Gasthof%20M%C3%BCller'
            .'&setting[]=lake&setting[]=countryside&access[]=station&access[]=airport&building[]=farm'
            .'&infra[]=heating_ventilation&infra[]=air_conditioning&furniture[]=chairs&tech[]=wifi&tech[]=projector_screen&surroundings[]=garden'
            .'&rooms=2&area=100&height=3.5&page=3',
        );

        self::assertSame(Canton::ZH, $criteria->canton);
        self::assertSame(self::TOWN, $criteria->place);
        self::assertSame(12, $criteria->radius);
        self::assertSame(GuestBand::UpTo100, $criteria->guests);
        self::assertSame('Gasthof Müller', $criteria->q);
        self::assertSame(['countryside', 'lake'], $criteria->termCodes(TermGroup::Setting));
        self::assertSame([Access::Airport, Access::Station], $criteria->access);
        self::assertSame(['air_conditioning', 'heating_ventilation'], $criteria->termCodes(TermGroup::Infrastructure));
        self::assertSame(['projector_screen', 'wifi'], $criteria->termCodes(TermGroup::Technology));
        self::assertSame(2, $criteria->rooms);
        self::assertSame(100, $criteria->area);
        self::assertSame(3.5, $criteria->height);
        self::assertSame(3, $criteria->page);

        $again = $this->fromUrl(http_build_query($criteria->toQuery()));
        self::assertEquals($criteria, $again);
        self::assertSame($criteria->toQuery(), $again->toQuery());
        self::assertSame(
            ['canton', 'place', 'radius', 'guests', 'q', 'setting', 'access', 'building', 'infra', 'furniture', 'tech', 'surroundings', 'rooms', 'area', 'height', 'page'],
            array_keys($criteria->toQuery()),
        );
    }

    public function test_the_event_filters_survive_the_url_round_trip_in_canonical_order(): void
    {
        $criteria = $this->fromUrl(
            'page=2&price[]=medium&price[]=exclusive&rooms=2'
            .'&amenity[]=overnight&amenity[]=lake_view&surroundings[]=garden',
        );

        self::assertSame(['lake_view', 'overnight'], $criteria->termCodes(TermGroup::Amenity));
        self::assertSame([PriceLevel::Exclusive, PriceLevel::Medium], $criteria->prices);
        self::assertSame(
            ['surroundings', 'amenity', 'price', 'rooms', 'page'],
            array_keys($criteria->toQuery()),
        );

        $again = $this->fromUrl(http_build_query($criteria->toQuery()));
        self::assertEquals($criteria, $again);
        self::assertSame($criteria->toQuery(), $again->toQuery());
    }

    public function test_a_url_without_the_event_filters_keeps_its_query(): void
    {
        $query = 'canton=zh&place=8712&radius=12&guests=100&q=See&setting%5B0%5D=lake&access%5B0%5D=station&building%5B0%5D=farm'
            .'&infra%5B0%5D=air_conditioning&furniture%5B0%5D=chairs&tech%5B0%5D=projector_screen&surroundings%5B0%5D=garden'
            .'&rooms=2&area=100&height=3.5&page=3';

        self::assertSame($query, http_build_query($this->fromUrl($query)->toQuery()));
    }

    public function test_with_page_and_with_canton_keep_the_event_filters(): void
    {
        $criteria = $this->fromUrl('price[]=medium&amenity[]=overnight');
        $expected = ['amenity' => ['overnight'], 'price' => ['medium']];

        self::assertSame($expected + ['page' => '2'], $criteria->withPage(2)->toQuery());
        self::assertSame(['canton' => 'zh'] + $expected, $criteria->withCanton(Canton::ZH)->toQuery());
    }

    public function test_prices_are_deduplicated_and_sorted(): void
    {
        $a = $this->fromUrl('price[]=moderate&price[]=exclusive&price[]=moderate');
        $b = $this->fromUrl('price[]=exclusive&price[]=moderate');

        self::assertSame(['price' => ['exclusive', 'moderate']], $a->toQuery());
        self::assertSame($a->toQuery(), $b->toQuery());
    }

    public function test_a_scalar_price_is_a_one_element_list(): void
    {
        self::assertSame([PriceLevel::Medium], $this->fromUrl('price=medium')->prices);
    }

    public function test_only_the_codes_of_filter_codes_filter(): void
    {
        $criteria = $this->fromUrl('amenity[]=tent&amenity[]=overnight&amenity[]=parking');

        self::assertSame(['amenity' => ['overnight']], $criteria->toQuery());
    }

    public function test_offers_narrows_only_the_groups_of_filter_codes(): void
    {
        self::assertTrue(SearchCriteria::offers(TermGroup::Amenity, 'overnight'));
        self::assertTrue(SearchCriteria::offers(TermGroup::Amenity, 'wheelchair_access'));
        self::assertFalse(SearchCriteria::offers(TermGroup::Amenity, 'tent'));
        self::assertTrue(SearchCriteria::offers(TermGroup::Setting, 'anything'));
        self::assertTrue(SearchCriteria::offers(TermGroup::Technology, 'anything'));
    }

    public function test_a_bookmarked_wedding_url_keeps_its_valid_filters_and_loses_the_retired_ones(): void
    {
        $criteria = $this->fromUrl('canton=zh&curfew=none&ceremony[]=chapel&usage[]=exclusive&amenity[]=overnight&price[]=medium');

        self::assertSame(['canton' => 'zh', 'amenity' => ['overnight'], 'price' => ['medium']], $criteria->toQuery());
    }

    public function test_the_query_bag_round_trip_without_a_url(): void
    {
        $criteria = $this->fromUrl('place=8712&radius=0&setting=lake&height=3');

        /** @var InputBag<string> $bag the shape Request::$query has */
        $bag = new InputBag($criteria->toQuery());

        self::assertEquals($criteria, SearchCriteria::fromQuery($bag, $this->known));
    }

    public function test_permuted_and_duplicated_input_gives_one_canonical_query(): void
    {
        $a = $this->fromUrl('setting[]=lake&setting[]=countryside&setting[]=lake&access[]=station&access[]=highway&canton=ZH');
        $b = $this->fromUrl('access[]=highway&canton=zh&setting[]=countryside&access[]=station&access[]=station&setting[]=lake');

        self::assertSame($a->toQuery(), $b->toQuery());
        self::assertSame(['canton' => 'zh', 'setting' => ['countryside', 'lake'], 'access' => ['highway', 'station']], $a->toQuery());
    }

    public function test_defaults(): void
    {
        self::assertSame([], SearchCriteria::none()->toQuery());
        self::assertSame(1, SearchCriteria::none()->page);
        self::assertSame([], $this->fromUrl('')->toQuery());

        $withoutRadius = $this->fromUrl('place='.self::TOWN);
        self::assertSame(5, $withoutRadius->radius);
        self::assertSame(['place' => self::TOWN], $withoutRadius->toQuery());

        $zero = $this->fromUrl('place=8712&radius=0');
        self::assertSame(0, $zero->radius);
        self::assertSame(['place' => '8712', 'radius' => '0'], $zero->toQuery());
        self::assertTrue($zero->isZipPlace());

        $radiusAlone = $this->fromUrl('radius=10');
        self::assertNull($radiusAlone->place);
        self::assertNull($radiusAlone->radius);
        self::assertSame([], $radiusAlone->toQuery());
    }

    public function test_a_town_uuid_is_lower_cased(): void
    {
        $criteria = $this->fromUrl('place='.strtoupper(self::TOWN));

        self::assertSame(self::TOWN, $criteria->place);
        self::assertFalse($criteria->isZipPlace());
    }

    #[DataProvider('garbage')]
    public function test_garbage_is_dropped_silently(string $url): void
    {
        self::assertSame([], $this->fromUrl($url)->toQuery());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        foreach (['abc', '0', '50001', '-5', '1e3', '', '123456'] as $guests) {
            yield 'guests='.$guests => ['guests='.$guests];
        }
        yield 'guests[]' => ['guests[]=5'];
        yield 'canton=xx' => ['canton=xx'];
        yield 'canton[]' => ['canton[]=zh'];
        foreach (['foo', '123', '12345', '0198a3c4-6b1e-7f3a-9c2d'] as $place) {
            yield 'place='.$place => ['place='.$place.'&radius=10'];
        }
        yield 'place[]' => ['place[]=8712'];
        yield 'q empty' => ['q='];
        yield 'q punctuation' => ['q=%25%25%25'];
        yield 'q blank' => ['q=%20%20'];
        yield 'q invalid UTF-8' => ['q=%C3%28'];
        yield 'q[]' => ['q[]=x'];
        yield 'setting unknown' => ['setting=unknown'];
        yield 'setting nested' => ['setting[][]=lake'];
        yield 'infra with a setting code' => ['infra=lake'];
        yield 'suitable_for is no filter' => ['suitable_for[]=wedding'];
        yield 'access moon' => ['access[]=moon'];
        yield 'access upper case' => ['access[]=STATION'];
        yield 'amenity unknown' => ['amenity[]=nope'];
        yield 'amenity outside FILTER_CODES' => ['amenity[]=tent'];
        yield 'price unknown' => ['price[]=cheap'];
        yield 'price upper case' => ['price[]=MEDIUM'];
        yield 'price nested' => ['price[][]=medium'];
        yield 'retired ceremony parameter' => ['ceremony[]=chapel'];
        yield 'retired usage parameter' => ['usage[]=exclusive'];
        yield 'retired curfew parameter' => ['curfew=none'];
        yield 'rooms=7' => ['rooms=7'];
        yield 'rooms=0' => ['rooms=0'];
        yield 'rooms[]' => ['rooms[]=2'];
        yield 'area=75' => ['area=75'];
        yield 'area=1000' => ['area=1000'];
        yield 'height=2.7' => ['height=2.7'];
        yield 'height=abc' => ['height=abc'];
        yield 'height=6' => ['height=6'];
        foreach (['0', 'abc', '1001', '1', '-2', '2.5'] as $page) {
            yield 'page='.$page => ['page='.$page];
        }
        yield 'page[]' => ['page[]=2'];
    }

    /**
     * @return iterable<string, array{string, GuestBand, string}>
     */
    public static function legacyGuests(): iterable
    {
        foreach ([
            '120' => GuestBand::UpTo150, '80' => GuestBand::UpTo100, '1' => GuestBand::UpTo50, '50' => GuestBand::UpTo50,
            '51' => GuestBand::UpTo100, '150' => GuestBand::UpTo150, '151' => GuestBand::Over150, '200' => GuestBand::Over150,
            '500' => GuestBand::Over150, '50000' => GuestBand::Over150,
        ] as $guests => $band) {
            yield 'guests='.$guests => [(string) $guests, $band, (string) $band->value];
        }
    }

    #[DataProvider('legacyGuests')]
    public function test_a_legacy_guest_count_maps_to_its_band(string $guests, GuestBand $band, string $canonical): void
    {
        $criteria = $this->fromUrl('guests='.$guests);

        self::assertSame($band, $criteria->guests);
        self::assertSame($canonical, $criteria->toQuery()['guests'] ?? null);
    }

    public function test_garbage_next_to_valid_values_keeps_the_valid_ones(): void
    {
        $criteria = $this->fromUrl('guests=abc&canton=zh&setting[]=nope&setting[]=lake&page[]=x&radius=999&q[]=1&rooms[]=2');

        self::assertSame(['canton' => 'zh', 'setting' => ['lake']], $criteria->toQuery());
    }

    public function test_out_of_range_radius_falls_back_to_the_default(): void
    {
        foreach (['51', '-1', 'abc', ''] as $radius) {
            self::assertSame(5, $this->fromUrl('place=8712&radius='.$radius)->radius, $radius);
        }
        self::assertSame(50, $this->fromUrl('place=8712&radius=50')->radius);
    }

    public function test_a_scalar_term_is_a_one_element_list(): void
    {
        self::assertSame(['lake'], $this->fromUrl('setting=lake')->termCodes(TermGroup::Setting));
        self::assertSame(['setting' => ['lake']], $this->fromUrl('setting=lake')->toQuery());
    }

    public function test_q_is_normalised(): void
    {
        self::assertSame('Gasthof Müller', $this->fromUrl('q=%20%20Gasthof%20%0A%09M%C3%BCller%20')->q);
        self::assertSame(str_repeat('a', 100), $this->fromUrl('q='.str_repeat('a', 150))->q);
        self::assertSame('日本', $this->fromUrl('q=%E6%97%A5%E6%9C%AC')->q);
    }

    public function test_height_is_written_with_one_decimal(): void
    {
        self::assertSame(['height' => '3.0'], $this->fromUrl('height=3')->toQuery());
        self::assertSame(['height' => '3.0'], $this->fromUrl('height=3.00')->toQuery());
        self::assertSame(2.5, $this->fromUrl('height=2.5')->height);
    }

    public function test_parameter_names_differ_from_group_values_for_infra_and_tech(): void
    {
        $criteria = $this->fromUrl('infra[]=heating_ventilation&tech[]=projector_screen&infrastructure[]=air_conditioning&technology[]=wifi');

        self::assertSame(['heating_ventilation'], $criteria->termCodes(TermGroup::Infrastructure));
        self::assertSame(['projector_screen'], $criteria->termCodes(TermGroup::Technology));
        self::assertSame(['infra' => ['heating_ventilation'], 'tech' => ['projector_screen']], $criteria->toQuery());
    }

    public function test_with_page_is_clamped(): void
    {
        self::assertSame(1, SearchCriteria::none()->withPage(0)->page);
        self::assertSame(1000, SearchCriteria::none()->withPage(5000)->page);
        self::assertSame(['page' => '7'], SearchCriteria::none()->withPage(7)->toQuery());
    }

    public function test_with_canton_keeps_the_other_filters(): void
    {
        $criteria = $this->fromUrl('guests=100&setting[]=lake&page=2')->withCanton(Canton::ZH);

        self::assertSame(['canton' => 'zh', 'guests' => '100', 'setting' => ['lake'], 'page' => '2'], $criteria->toQuery());
        self::assertNull($criteria->withCanton(null)->canton);
    }

    private function fromUrl(string $queryString): SearchCriteria
    {
        parse_str($queryString, $parameters);
        /** @var array<string, mixed> $parameters */
        /** @var InputBag<string> $bag the shape Request::$query has */
        $bag = new InputBag($parameters);

        return SearchCriteria::fromQuery($bag, $this->known);
    }
}
