<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Shared\Translation\EnabledLocale;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\TestImages;
use App\Venues\ContentReader;
use App\Venues\Exception\InvalidContent;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use PHPUnit\Framework\TestCase;

/**
 * ContentReader against fictional folders built at runtime.
 */
final class ContentReaderTest extends TestCase
{
    private ContentFolder $content;
    private ContentReader $reader;

    protected function setUp(): void
    {
        $this->content = new ContentFolder(\dirname(__DIR__, 3).'/var/test-content-reader-'.uniqid());
        $this->reader = new ContentReader(ContentSchemaTest::schema(), new EnabledLocale(['de'], 'de'));
    }

    protected function tearDown(): void
    {
        $this->content->removeAll();
    }

    public function test_a_complete_folder_is_read_with_normalised_values(): void
    {
        $full = ContentSchemaTest::full();
        unset($full['photos'], $full['rooms'], $full['documents']);
        $this->content->venue('schloss-beispiel', $full);
        $this->content->edit('schloss-beispiel', static function (array $data): array {
            $data['rooms'] = [['key' => 'rittersaal', 'translations' => ['de' => ['name' => 'Rittersaal']], 'height_m' => 6.5, 'floor_material' => 'parquet', 'seating' => ['apero' => ['max' => 150]]]];

            return $data;
        });
        $this->content->photo('schloss-beispiel', 'aussen.JPEG', alt: ['de' => 'Aussen']);
        $this->content->photo('schloss-beispiel', 'saal.png', 'rittersaal', TestImages::transparentPng(40, 20));
        $this->content->document('schloss-beispiel', 'preisliste.pdf');

        $venue = $this->reader->read($this->content->folder('schloss-beispiel'));

        self::assertSame('schloss-beispiel', $venue->key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $venue->contentHash);
        self::assertSame([Tier::Premium, VenueStatus::Published, 180, 20], [$venue->tier, $venue->status, $venue->capacityMax, $venue->capacityMin]);
        self::assertSame(['8001', 'Zürich', 'zh'], [$venue->zip, $venue->townName, $venue->cantonCode]);
        self::assertSame(['0.9', 'Bahnhof Beispiel', '3.2', 'A1', '12.0', null], [$venue->stationKm, $venue->stationName, $venue->highwayKm, $venue->highwayName, $venue->airportKm, $venue->airportName]);
        self::assertSame([OwnCatering::Allowed, null, '9.0', 47], [$venue->ownCatering, $venue->daylight, $venue->ratingScore, $venue->ratingCount]);
        self::assertSame([RatingHighlight::Food, RatingHighlight::Atmosphere], $venue->highlights);
        self::assertSame(['castle', 'event_venue'], $venue->terms['building']);
        self::assertSame(['building' => 'castle', 'setting' => 'lake'], $venue->mainTerms);
        self::assertSame(['summary' => 'Kurz.', 'description' => 'Lang.', 'metaDescription' => 'Meta.', 'parkingText' => 'Parkplätze.', 'ambience' => 'Romantisch am See', 'capacityNote' => 'Mit Tanzfläche 100 Plätze.', 'partyNote' => 'Musik bis 2 Uhr.', 'openingHours' => 'Mai bis Oktober', 'region' => null], $venue->translations['de']);
        self::assertSame([PriceLevel::Medium, 120, 180], [$venue->priceLevel, $venue->capacitySeated, $venue->capacityStanding]);
        self::assertSame([PartyAllowed::Limited, true, '02:00'], [$venue->party, $venue->hasCurfew, $venue->curfewAt?->format('H:i')]);
        self::assertSame('https://www.instagram.com/schloss.beispiel', $venue->instagram);
        self::assertSame(['de', 'gsw'], $venue->terms['language']);
        self::assertCount(1, $venue->photos);
        self::assertSame(['aussen.JPEG', 'jpg', ['de' => 'Aussen']], [$venue->photos[0]->file, $venue->photos[0]->extension, $venue->photos[0]->alt]);
        self::assertSame(hash_file('sha256', $this->content->folder('schloss-beispiel').'/photos/aussen.JPEG'), $venue->photos[0]->sha256);
        self::assertSame(['rittersaal', '6.50', FloorMaterial::Parquet, ['apero' => ['min' => null, 'max' => 150]]], [$venue->rooms[0]->key, $venue->rooms[0]->heightM, $venue->rooms[0]->floorMaterial, $venue->rooms[0]->seating]);
        self::assertSame('png', $venue->rooms[0]->photos[0]->extension);
        self::assertSame(['preisliste.pdf', ['de' => 'Preisliste']], [$venue->documents[0]->file, $venue->documents[0]->title]);
        self::assertGreaterThan(100, $venue->documents[0]->sizeBytes);
        self::assertSame(['Laura', ReviewerRole::VisitorF, '2025-06-01', 'de'], [$venue->reviews[0]->firstName, $venue->reviews[0]->role, $venue->reviews[0]->month->format('Y-m-d'), $venue->reviews[0]->locale]);
    }

    public function test_defaults_for_status_and_review_locale(): void
    {
        $this->content->venue('a-venue', ['reviews' => [['first_name' => 'Ben', 'role' => 'visitor_m', 'month' => '2024-12', 'text' => 'Gut.']]]);

        $venue = $this->reader->read($this->content->folder('a-venue'));

        self::assertSame(VenueStatus::Published, $venue->status);
        self::assertSame('de', $venue->reviews[0]->locale);
        self::assertSame([], $venue->photos);
    }

    public function test_a_folder_with_only_a_canton_is_read(): void
    {
        $this->content->venue('a-venue', ['address' => ['street' => null, 'zip' => null, 'town' => null, 'canton' => 'be'], 'geo' => null]);

        $venue = $this->reader->read($this->content->folder('a-venue'));

        self::assertSame([null, null, null, 'be', null, null], [$venue->street, $venue->zip, $venue->townName, $venue->cantonCode, $venue->lat, $venue->lng]);
    }

    public function test_a_folder_with_no_address_and_a_region_is_read(): void
    {
        $this->content->venue('a-venue', ['address' => null, 'geo' => null, 'translations' => ['de' => ['region' => 'Schweizweit']]]);

        $venue = $this->reader->read($this->content->folder('a-venue'));

        self::assertSame([null, null, null, null, null], [$venue->zip, $venue->townName, $venue->cantonCode, $venue->lat, $venue->lng]);
        self::assertSame('Schweizweit', $venue->translations['de']['region']);
    }

    public function test_the_hash_is_stable_and_follows_the_files(): void
    {
        $this->content->venue('a-venue')->photo('a-venue', 'a.jpg');
        $folder = $this->content->folder('a-venue');
        $first = $this->reader->read($folder);

        self::assertSame($first->contentHash, $this->reader->read($folder)->contentHash);

        $this->content->file('a-venue', 'photos/a.jpg', TestImages::uniformJpeg(80, 40, 10, 20, 30));
        $changed = $this->reader->read($folder);
        self::assertNotSame($first->contentHash, $changed->contentHash);
        self::assertNotSame($first->photos[0]->sha256, $changed->photos[0]->sha256);

        rename($folder.'/photos/a.jpg', $folder.'/photos/b.jpg');
        $this->content->change('a-venue', ['photos' => [['file' => 'b.jpg']]]);
        $renamed = $this->reader->read($folder);
        self::assertNotSame($changed->contentHash, $renamed->contentHash);
        self::assertSame($changed->photos[0]->sha256, $renamed->photos[0]->sha256);
    }

    public function test_key_order_in_the_yaml_does_not_change_the_hash(): void
    {
        $this->content->venue('a-venue');
        $hash = $this->reader->read($this->content->folder('a-venue'))->contentHash;

        $this->content->edit('a-venue', static fn (array $data): array => array_reverse($data, true));

        self::assertSame($hash, $this->reader->read($this->content->folder('a-venue'))->contentHash);
    }

    public function test_keys_are_sorted_and_files_are_warned_about(): void
    {
        $this->content->venue('b-venue')->venue('a-venue')->venue('a-venue-2');
        TestImages::write($this->content->path().'/venues', 'notes.txt', 'x');
        TestImages::write($this->content->path().'/venues/.hidden', 'x', 'x');

        $keys = $this->reader->keys($this->content->path());

        self::assertSame(['a-venue', 'a-venue-2', 'b-venue'], $keys['keys']);
        self::assertSame(['venues/notes.txt is a file, not a venue folder; ignored'], $keys['warnings']);
    }

    /**
     * @return iterable<string, array{callable(ContentFolder): string, string}>
     */
    public static function invalidFolders(): iterable
    {
        yield 'invalid folder name' => [static function (ContentFolder $c): string {
            $c->venue('Bad_Name');

            return 'Bad_Name';
        }, 'invalid folder name "Bad_Name"'];
        yield 'missing venue.yaml' => [static function (ContentFolder $c): string {
            $c->file('a-venue', 'photos/a.jpg', TestImages::jpeg(10, 10));

            return 'a-venue';
        }, 'venue.yaml is missing'];
        yield 'YAML syntax error' => [static function (ContentFolder $c): string {
            $c->rawYaml('a-venue', "name: [unclosed\n");

            return 'a-venue';
        }, 'venue.yaml: '];
        yield 'not a mapping' => [static function (ContentFolder $c): string {
            $c->rawYaml('a-venue', "- a\n- b\n");

            return 'a-venue';
        }, 'must be a mapping'];
        yield 'min above max' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['capacity' => ['min' => 200, 'max' => 100]]);

            return 'a-venue';
        }, '/capacity: min (200) is greater than max (100)'];
        yield 'seating min above max' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['rooms' => [['key' => 'saal', 'translations' => ['de' => ['name' => 'Saal']], 'seating' => ['banquet' => ['min' => 90, 'max' => 10]]]]]);

            return 'a-venue';
        }, '/rooms/0/seating/banquet: min (90) is greater than max (10)'];
        yield 'main term not listed' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['terms' => ['building' => ['villa']], 'main_terms' => ['building' => 'castle']]);

            return 'a-venue';
        }, '/main_terms/building: "castle" is not listed under terms.building'];
        yield 'curfew time without has_curfew' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['party' => ['curfew_at' => '02:00']]);

            return 'a-venue';
        }, '/party/curfew_at: needs has_curfew: true'];
        yield 'curfew time with has_curfew false' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['party' => ['has_curfew' => false, 'curfew_at' => '02:00']]);

            return 'a-venue';
        }, '/party/curfew_at: needs has_curfew: true'];
        yield 'fr translation' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['translations' => ['fr' => ['summary' => 'Court.']]]);

            return 'a-venue';
        }, '/translations/fr: locale "fr" is not enabled'];
        yield 'too many decimals' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['distances' => ['station' => ['km' => 1.25]]]);

            return 'a-venue';
        }, '/distances/station/km: 1.25 has more than 1 decimal'];
        yield 'room height with three decimals' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['rooms' => [['key' => 'saal', 'translations' => ['de' => ['name' => 'Saal']], 'height_m' => 3.125]]]);

            return 'a-venue';
        }, '/rooms/0/height_m: 3.125 has more than 2 decimals'];
        yield 'room key twice' => [static function (ContentFolder $c): string {
            $room = ['key' => 'saal', 'translations' => ['de' => ['name' => 'Saal']]];
            $c->venue('a-venue', ['rooms' => [$room, $room]]);

            return 'a-venue';
        }, '/rooms/1/key: room key "saal" is used twice'];
        yield 'missing file' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['photos' => [['file' => 'fehlt.jpg']]]);

            return 'a-venue';
        }, 'photos/fehlt.jpg: the file is missing'];
        yield 'unlisted file' => [static function (ContentFolder $c): string {
            $c->venue('a-venue')->file('a-venue', 'photos/extra.jpg', TestImages::jpeg(10, 10));

            return 'a-venue';
        }, 'photos/extra.jpg: not listed in venue.yaml'];
        yield 'orphan room folder' => [static function (ContentFolder $c): string {
            $c->venue('a-venue')->file('a-venue', 'rooms/keller/photos/a.jpg', TestImages::jpeg(10, 10));

            return 'a-venue';
        }, 'rooms/keller: no room with this key in venue.yaml'];
        yield 'duplicate photo' => [static function (ContentFolder $c): string {
            $c->venue('a-venue')->photo('a-venue', 'a.jpg')->photo('a-venue', 'b.jpg');

            return 'a-venue';
        }, 'photos/b.jpg: the same image as photos/a.jpg'];
        yield 'gif renamed to jpg' => [static function (ContentFolder $c): string {
            $gif = imagecreatetruecolor(4, 4);
            ob_start();
            imagegif($gif);
            $c->venue('a-venue')->photo('a-venue', 'a.jpg', bytes: (string) ob_get_clean());

            return 'a-venue';
        }, 'photos/a.jpg: not a JPEG, PNG or WebP image'];
        yield 'document not a PDF' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['documents' => [['file' => 'a.pdf', 'title' => ['de' => 'A']]]])->file('a-venue', 'documents/a.pdf', 'hello');

            return 'a-venue';
        }, 'documents/a.pdf: not a PDF'];
        yield 'zip without a town' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['address' => ['street' => null, 'town' => null]]);

            return 'a-venue';
        }, '/address: zip and town come together'];
        yield 'town without a zip' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['address' => ['street' => null, 'zip' => null]]);

            return 'a-venue';
        }, '/address: zip and town come together'];
        yield 'street without a town' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', ['address' => ['zip' => null, 'town' => null]]);

            return 'a-venue';
        }, '/address/street: needs zip and town'];
        yield 'name without letters' => [static function (ContentFolder $c): string {
            $c->venue('a-venue', name: '…');

            return 'a-venue';
        }, '/name: the name gives an empty URL slug'];
    }

    /**
     * @param callable(ContentFolder): string $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFolders')]
    public function test_an_invalid_folder_fails_with_a_message(callable $build, string $expected): void
    {
        $key = $build($this->content);

        try {
            $this->reader->read($this->content->folder($key));
            self::fail('The folder should be invalid.');
        } catch (InvalidContent $exception) {
            self::assertSame($key, $exception->key());
            self::assertNotEmpty(array_filter($exception->messages(), static fn (string $m): bool => str_contains($m, $expected)), implode("\n", $exception->messages()));
        }
    }

    public function test_a_dot_file_is_ignored(): void
    {
        $this->content->venue('a-venue')->file('a-venue', 'photos/.DS_Store', 'x')->file('a-venue', 'documents/._a.pdf', 'x');

        self::assertSame('a-venue', $this->reader->read($this->content->folder('a-venue'))->key);
    }

    public function test_several_violations_come_back_together(): void
    {
        $this->content->venue('a-venue', [
            'capacity' => ['min' => 200, 'max' => 100],
            'translations' => ['fr' => ['summary' => 'Court.']],
            'photos' => [['file' => 'fehlt.jpg']],
        ]);

        try {
            $this->reader->read($this->content->folder('a-venue'));
            self::fail('The folder should be invalid.');
        } catch (InvalidContent $exception) {
            self::assertCount(3, $exception->messages(), implode("\n", $exception->messages()));
            self::assertStringContainsString('(+2 more)', $exception->getMessage());
        }
    }

    /**
     * Review F-m1: a link could pull any file of the host into MEDIA_ROOT and public/media.
     *
     * @return iterable<string, array{callable(ContentFolder, string): string}>
     */
    public static function linkedPaths(): iterable
    {
        yield 'listed photo' => [static function (ContentFolder $c, string $outside): string {
            $c->venue('a-venue')->photo('a-venue', 'a.jpg');
            self::link($c->folder('a-venue').'/photos/a.jpg', $outside.'/fremd.jpg', TestImages::jpeg(40, 20));

            return 'photos/a.jpg: symbolic links are not allowed';
        }];
        yield 'venue.yaml' => [static function (ContentFolder $c, string $outside): string {
            $c->venue('a-venue');
            self::link($c->folder('a-venue').'/venue.yaml', $outside.'/venue.yaml');

            return 'venue.yaml: symbolic links are not allowed';
        }];
        yield 'photos folder' => [static function (ContentFolder $c, string $outside): string {
            $c->venue('a-venue')->photo('a-venue', 'a.jpg');
            self::link($c->folder('a-venue').'/photos', $outside.'/photos');

            return 'photos: symbolic links are not allowed';
        }];
        yield 'room folder' => [static function (ContentFolder $c, string $outside): string {
            $c->venue('a-venue', ['rooms' => [['key' => 'saal', 'translations' => ['de' => ['name' => 'Saal']]]]])->photo('a-venue', 'a.jpg', 'saal');
            self::link($c->folder('a-venue').'/rooms/saal', $outside.'/saal');

            return 'rooms/saal: symbolic links are not allowed';
        }];
        yield 'venue folder' => [static function (ContentFolder $c, string $outside): string {
            $c->venue('a-venue');
            self::link($c->folder('a-venue'), $outside.'/a-venue');

            return 'the venue folder is a symbolic link; symbolic links are not allowed';
        }];
    }

    /**
     * @param callable(ContentFolder, string): string $build
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('linkedPaths')]
    public function test_a_symbolic_link_fails_the_venue(callable $build): void
    {
        $outside = $this->content->path().'/outside';
        mkdir($outside, recursive: true);
        $expected = $build($this->content, $outside);

        try {
            $this->reader->read($this->content->folder('a-venue'));
            self::fail('A folder with a symbolic link should be invalid.');
        } catch (InvalidContent $exception) {
            self::assertContains($expected, $exception->messages());
        }
    }

    public function test_a_linked_venue_folder_is_still_listed(): void
    {
        $outside = $this->content->path().'/outside';
        mkdir($outside, recursive: true);
        $this->content->venue('a-venue');
        self::link($this->content->folder('a-venue'), $outside.'/a-venue');

        self::assertSame(['a-venue'], $this->reader->keys($this->content->path())['keys']);
    }

    /**
     * Moves $path to $target (or writes $bytes there instead) and puts a link to it at $path.
     */
    private static function link(string $path, string $target, ?string $bytes = null): void
    {
        if (null === $bytes) {
            rename($path, $target);
        } else {
            unlink($path);
            file_put_contents($target, $bytes);
        }
        symlink($target, $path);
    }
}
