<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Most of the owner's venues name a canton or a region and no address (#21, S8): the import
 * writes them as they are and invents nothing.
 */
final class ImportVenueWithoutAddressTest extends KernelTestCase
{
    use RunsContentImport;

    private const NO_ADDRESS = ['street' => null, 'zip' => null, 'town' => null];

    public function test_a_canton_only_folder_imports_without_a_town(): void
    {
        $this->content->venue('a-venue', ['address' => [...self::NO_ADDRESS, 'canton' => 'be'], 'geo' => null]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $venue = $this->venueRow('a-venue');
        self::assertIsArray($venue);
        self::assertSame('be', $venue['canton_code']);
        self::assertNull($venue['town_id']);
        self::assertNull($venue['zip']);
        self::assertNull($venue['street']);
        self::assertNull($venue['lat']);
        self::assertNull($venue['lng']);
    }

    public function test_a_liechtenstein_folder_imports_with_canton_li_and_its_pin(): void
    {
        $this->content->venue('a-venue', ['address' => [...self::NO_ADDRESS, 'canton' => 'li'], 'geo' => ['lat' => 47.141, 'lng' => 9.521]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $venue = $this->venueRow('a-venue');
        self::assertIsArray($venue);
        self::assertSame(['li', null, 47.141, 9.521], [$venue['canton_code'], $venue['town_id'], $venue['lat'], $venue['lng']]);
    }

    public function test_a_region_text_folder_keeps_the_text_and_has_no_canton(): void
    {
        $this->content->venue('a-venue', ['address' => null, 'geo' => null, 'translations' => ['de' => ['region' => 'Schweizweit']]]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $venue = $this->venueRow('a-venue');
        self::assertIsArray($venue);
        self::assertNull($venue['canton_code']);
        self::assertSame('Schweizweit', $this->connection->fetchOne('SELECT region FROM venue_translations WHERE venue_id = ?', [$this->venueId('a-venue')]));
    }

    public function test_a_folder_with_no_place_at_all_imports(): void
    {
        $this->content->venue('a-venue', ['address' => null, 'geo' => null]);

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $venue = $this->venueRow('a-venue');
        self::assertIsArray($venue);
        self::assertNull($venue['town_id']);
        self::assertNull($venue['canton_code']);
    }

    public function test_an_update_moves_a_venue_from_a_town_to_a_canton_and_back(): void
    {
        $this->content->venue('a-venue');
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $withTown = $this->venueRow('a-venue');
        self::assertIsArray($withTown);
        self::assertNotNull($withTown['town_id']);
        self::assertSame('zh', $withTown['canton_code']);

        $this->content->change('a-venue', ['address' => [...self::NO_ADDRESS, 'canton' => 'be'], 'geo' => null, 'translations' => ['de' => ['region' => 'Bernbiet']]]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $withCanton = $this->venueRow('a-venue');
        self::assertIsArray($withCanton);
        self::assertSame([null, null, null, 'be', null, null], [$withCanton['town_id'], $withCanton['zip'], $withCanton['street'], $withCanton['canton_code'], $withCanton['lat'], $withCanton['lng']]);

        $this->content->change('a-venue', ['address' => ['street' => 'Musterweg 1', 'zip' => '8001', 'town' => 'Zürich', 'canton' => null], 'geo' => ['lat' => 47.37, 'lng' => 8.54], 'translations' => ['de' => ['region' => null]]]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $back = $this->venueRow('a-venue');
        self::assertIsArray($back);
        self::assertSame($withTown['town_id'], $back['town_id']);
        self::assertSame(['8001', 'Musterweg 1', 'zh', 47.37, 8.54], [$back['zip'], $back['street'], $back['canton_code'], $back['lat'], $back['lng']]);
        self::assertNull($this->connection->fetchOne('SELECT region FROM venue_translations WHERE venue_id = ?', [$this->venueId('a-venue')]));
    }

    public function test_a_slug_collision_without_a_town_gets_a_counter(): void
    {
        $this->content->venue('a-venue', ['address' => null, 'geo' => null], 'Gasthaus Gleich');
        $this->content->venue('b-venue', ['address' => null, 'geo' => null], 'Gasthaus Gleich');

        self::assertSame(Command::SUCCESS, $this->import(), $this->display());

        $slugs = $this->connection->fetchFirstColumn('SELECT slug FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.content_key IS NOT NULL ORDER BY slug');
        self::assertSame(['gasthaus-gleich', 'gasthaus-gleich-2'], $slugs);
    }

    public function test_an_unknown_canton_fails_with_a_pointer_and_reaches_no_database_error(): void
    {
        $this->content->venue('a-venue', ['address' => [...self::NO_ADDRESS, 'canton' => 'xx'], 'geo' => null]);

        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('a-venue: /address/canton: "xx" is not an allowed value', $this->display());
        self::assertFalse($this->venueRow('a-venue'));
    }

    public function test_a_zip_without_a_town_fails(): void
    {
        $this->content->venue('a-venue', ['address' => ['street' => null, 'town' => null], 'geo' => null]);

        self::assertSame(Command::FAILURE, $this->import());

        self::assertStringContainsString('a-venue: /address: zip and town come together', $this->display());
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->content->venue('a-venue', ['address' => [...self::NO_ADDRESS, 'canton' => 'be'], 'geo' => null]);
        $this->content->venue('b-venue', ['address' => null, 'geo' => null, 'translations' => ['de' => ['region' => 'Schweizweit']]]);
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        $this->em->clear();

        $this->import();

        self::assertStringContainsString('0 created, 0 updated, 2 unchanged, 0 failed', $this->display());
    }
}
