<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\EventlokaleCache;
use App\Crm\EventlokaleSheet;
use App\Tests\Support\Crm\EventlokalePayloads;
use App\Venues\ContentSchema;
use App\Venues\Sheet\VenueSheet;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The crawl's draft sheet (F14, D32): the venue sheet's columns with facts only. Texts, photos, ratings, terms and
 * contact persons never reach it, a suppressed address leaves the e-mail empty, and the file converts as a sheet.
 */
final class EventlokaleSheetTest extends TestCase
{
    private const array NEVER_COPIED = ['summary', 'description', 'contact_person', 'instagram', 'event_types', 'building', 'region', 'price_level', 'consent_on', 'consent_from'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/eventlokale-sheet-'.bin2hex(random_bytes(4));
        $cache = new EventlokaleCache($this->root);
        $cache->writeTaxonomies(EventlokalePayloads::taxonomies());
        $cache->writeIndex(370, [EventlokalePayloads::listItem(2), EventlokalePayloads::listItem(1), EventlokalePayloads::listItem(3)], 3, new \DateTimeImmutable('2026-11-03 10:00:00'));
        $cache->writeDetail(1, EventlokalePayloads::detail(1));
        $cache->writeDetail(2, EventlokalePayloads::detail(2, ['owner' => ['email' => 'Gesperrt@Example.test', 'website' => 'https://www.musterberg.example.test']]));
        $cache->markGone(3);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root.'/eventlokale/venues/*') ?: []);
        array_map('unlink', glob($this->root.'/eventlokale/*.json') ?: []);
        array_map('unlink', glob($this->root.'/owner/*') ?: []);
        @rmdir($this->root.'/eventlokale/venues');
        @rmdir($this->root.'/eventlokale');
        @rmdir($this->root.'/owner');
        @rmdir($this->root);
    }

    public function test_it_writes_the_facts_of_every_fetched_venue_in_the_sheet_columns(): void
    {
        self::assertSame(2, (new EventlokaleSheet())->write($this->root, ['gesperrt@example.test']));

        $file = $this->root.'/'.EventlokaleSheet::FILE;
        $contents = (string) file_get_contents($file);
        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertSame(VenueSheet::HEADER, str_getcsv(explode("\r\n", substr($contents, 3))[0], ';', '"', ''));
        foreach (['Ein erfundenes', 'Erika', 'Muster Events', 'test-1.jpg', 'Rittersaal', EventlokalePayloads::SECRET_COMMENT] as $never) {
            self::assertStringNotContainsString($never, $contents);
        }

        $rows = VenueSheet::read($file);
        self::assertCount(2, $rows);
        [$first, $second] = $rows;
        self::assertSame(
            ['eventlokale', 'Testschloss Musterberg am See 1', 'Musterweg 7a', '9999', 'Musterdorf', '47.0000000', '8.0000000', '10', '150', '+41 00 000 00 00', 'info@example.test', 'https://www.example.test'],
            array_map($first->cell(...), ['source', 'name', 'street', 'zip', 'town', 'lat', 'lng', 'capacity_min', 'capacity_max', 'phone', 'inquiry_email', 'website']),
        );
        foreach (self::NEVER_COPIED as $column) {
            self::assertSame('', $first->cell($column), $column);
        }
        self::assertSame('', $second->cell('inquiry_email'), 'a suppressed address stays out');
        self::assertSame('https://www.musterberg.example.test', $second->cell('website'));

        $sheet = new VenueSheet(new ContentSchema(\dirname(__DIR__, 3)), new MockClock('2026-11-08'));
        self::assertSame([[], []], array_map($sheet->check(...), $rows));
        self::assertSame('testschloss-musterberg-am-see-1-musterdorf', $first->key());
        self::assertObjectNotHasProperty('translations', $first->toDocument());
        self::assertEquals((object) ['phone' => '+41000000000', 'inquiry_email' => 'info@example.test', 'website' => 'https://www.example.test'], $first->toDocument()->contact);
    }
}
