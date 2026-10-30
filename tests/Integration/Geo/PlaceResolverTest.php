<?php

declare(strict_types=1);

namespace App\Tests\Integration\Geo;

use App\Geo\ReadModel\PlaceResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlaceResolverTest extends KernelTestCase
{
    use ImportsTownDataset;

    private const UNKNOWN_ID = '0198a3c4-6b1e-7f3a-9c2d-1e4f5a6b7c8d';

    private Connection $connection;
    private PlaceResolver $resolver;

    protected function setUp(): void
    {
        // Built by hand like every read-model test: the class's own SQL is under test, not its wiring.
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->resolver = new PlaceResolver($connection);
    }

    public function test_a_town_id_resolves_to_its_town(): void
    {
        $id = $this->connection->fetchOne("SELECT id FROM towns WHERE name = 'Stäfa'");
        self::assertIsString($id);

        $place = $this->resolver->resolve($id);

        self::assertNotNull($place);
        self::assertSame('Stäfa', $place->name);
        self::assertSame('8712', $place->zip);
        self::assertEqualsWithDelta(47.24, $place->lat, 0.1);
        self::assertEqualsWithDelta(8.72, $place->lng, 0.1);
    }

    public function test_a_zip_resolves_to_its_town_and_labels_both(): void
    {
        $place = $this->resolver->resolve('8712');

        self::assertNotNull($place);
        self::assertSame('Stäfa', $place->name);
        self::assertSame('8712', $place->zip);
        self::assertSame('8712 Stäfa', $place->label());
        self::assertEqualsWithDelta(47.24, $place->lat, 0.1);
    }

    public function test_a_shared_zip_resolves_to_the_most_populous_town(): void
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT tz.zip, (array_agg(t.name ORDER BY t.population DESC, t.name))[1] AS name
                FROM town_zips tz
                JOIN towns t ON t.id = tz.town_id
                GROUP BY tz.zip
                HAVING count(*) > 1
                ORDER BY tz.zip
                LIMIT 1
                SQL,
        );
        self::assertIsArray($row);
        self::assertIsString($row['zip']);

        self::assertSame($row['name'], $this->resolver->resolve($row['zip'])?->name);
    }

    public function test_unknown_values_resolve_to_nothing(): void
    {
        self::assertNull($this->resolver->resolve(self::UNKNOWN_ID));
        self::assertNull($this->resolver->resolve('9999'));
        self::assertNull($this->resolver->resolve('no-such-place'));
    }

    public function test_it_memoizes_until_reset(): void
    {
        $first = $this->resolver->resolve('8712');

        self::assertNotNull($first);
        self::assertSame($first, $this->resolver->resolve('8712'));

        $this->resolver->reset();

        self::assertNotSame($first, $this->resolver->resolve('8712'));
    }
}
