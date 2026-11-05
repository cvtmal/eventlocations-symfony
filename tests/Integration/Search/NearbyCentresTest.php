<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Search\NearbyCentres;
use App\Search\ValueObject\NearbyRequest;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #52: the near-me page's query to a centre, against the real towns (D2, D3).
 */
final class NearbyCentresTest extends KernelTestCase
{
    use ImportsTownDataset;

    public function test_a_position_is_rounded_and_has_no_label(): void
    {
        $request = $this->centres()->resolve(['lat' => '47.2289', 'lng' => '8.6696']);

        self::assertNotNull($request->centre);
        self::assertSame(47.23, $request->centre->point->lat);
        self::assertSame(8.67, $request->centre->point->lng);
        self::assertNull($request->centre->label);
        self::assertNull($request->centre->listingPlace);
        self::assertNull($request->notice);
    }

    public function test_a_zip_is_its_most_populous_town_and_keeps_the_zip_for_the_listing(): void
    {
        $request = $this->centres()->resolve(['ort' => ' 8712 ']);

        self::assertNotNull($request->centre);
        self::assertSame('8712 Stäfa', $request->centre->label);
        self::assertSame('8712', $request->centre->listingPlace);
        self::assertSame('8712', $request->ort);
    }

    #[DataProvider('stafa')]
    public function test_a_town_name_takes_the_first_town(string $ort): void
    {
        $request = $this->centres()->resolve(['ort' => $ort]);

        self::assertNotNull($request->centre);
        self::assertSame('8712 Stäfa', $request->centre->label);
        self::assertSame($this->townId('Stäfa'), $request->centre->listingPlace);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stafa(): iterable
    {
        yield 'umlaut' => ['Stäfa'];
        yield 'folded' => ['Staefa'];
    }

    public function test_a_name_that_is_also_a_canton_takes_the_town(): void
    {
        $request = $this->centres()->resolve(['ort' => 'Zürich']);

        self::assertNotNull($request->centre);
        self::assertStringEndsWith(' Zürich', (string) $request->centre->label);
        self::assertSame($this->townId('Zürich'), $request->centre->listingPlace);
    }

    #[DataProvider('notFound')]
    public function test_a_miss_is_a_not_found_notice(string $ort): void
    {
        $request = $this->centres()->resolve(['ort' => $ort]);

        self::assertNull($request->centre);
        self::assertSame(NearbyRequest::NOT_FOUND, $request->notice);
        self::assertSame($ort, $request->ort);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFound(): iterable
    {
        yield 'gibberish' => ['xyzxyz'];
        yield 'an unknown zip' => ['0001'];
    }

    public function test_a_canton_only_match_is_not_a_centre(): void
    {
        // "Graubünden" names a canton and no town.
        $request = $this->centres()->resolve(['ort' => 'Graubünden']);

        self::assertNull($request->centre);
        self::assertSame(NearbyRequest::NOT_FOUND, $request->notice);
    }

    /**
     * @param array<string, mixed> $query
     */
    #[DataProvider('badPositions')]
    public function test_a_bad_position_is_a_position_notice(array $query): void
    {
        $request = $this->centres()->resolve($query);

        self::assertNull($request->centre);
        self::assertSame(NearbyRequest::POSITION, $request->notice);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badPositions(): iterable
    {
        yield 'Berlin' => [['lat' => '52.52', 'lng' => '13.40']];
        yield 'no lng' => [['lat' => '47.3']];
        yield 'an array' => [['lat' => ['47.3'], 'lng' => '8.5']];
    }

    public function test_an_array_or_an_empty_ort_is_no_location_and_no_notice(): void
    {
        foreach ([['ort' => ['8712']], ['ort' => ''], ['ort' => '  '], []] as $query) {
            $request = $this->centres()->resolve($query);

            self::assertNull($request->centre);
            self::assertNull($request->notice);
        }
    }

    public function test_a_position_wins_over_an_ort(): void
    {
        $request = $this->centres()->resolve(['lat' => '46.95', 'lng' => '7.45', 'ort' => '8712']);

        self::assertNotNull($request->centre);
        self::assertNull($request->centre->label);
        self::assertSame(46.95, $request->centre->point->lat);
    }

    private function centres(): NearbyCentres
    {
        $centres = self::getContainer()->get(NearbyCentres::class);
        self::assertInstanceOf(NearbyCentres::class, $centres);

        return $centres;
    }

    private function townId(string $name): string
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $id = $connection->fetchOne('SELECT id FROM towns WHERE name = :name ORDER BY population DESC LIMIT 1', ['name' => $name]);
        self::assertIsString($id);

        return $id;
    }
}
