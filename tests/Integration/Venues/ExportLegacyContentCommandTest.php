<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Venues\LegacyVenueText;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * app:legacy:export-content on a fictional CSV built at runtime, against folders under var/.
 * The import half uses RunsContentImport, so the output is proven through #9's real reader.
 */
final class ExportLegacyContentCommandTest extends KernelTestCase
{
    use RunsContentImport {
        setUp as private setUpImport;
    }

    private const SECOND_ID = '2b3c4d5e-0000-4000-8000-000000000002';

    private string $csv;
    private CommandTester $exporter;

    protected function setUp(): void
    {
        $this->setUpImport();
        $this->csv = $this->content->path().'/owner.csv';
        \assert(null !== self::$kernel);
        $this->exporter = new CommandTester((new Application(self::$kernel))->find('app:legacy:export-content'));
    }

    public function test_it_writes_folders_and_a_dry_run_only_reports_them(): void
    {
        $this->csv([WixCsv::row(), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID, 'Region' => 'Basel'])]);

        self::assertSame(Command::SUCCESS, $this->export(['--dry-run' => true]));
        $dry = $this->exporter->getDisplay();
        self::assertStringContainsString('Dry run: Rows: 2. Mapped: 2 (2 created, 0 updated, 0 unchanged). Skipped: 0. Unresolved: 0.', $dry);
        self::assertStringContainsString('Place: 1 canton, 1 region text (Basel 1). Cantons used: 1.', $dry);
        self::assertStringContainsString('Contact: 0 phone dropped, 0 phones empty, 0 e-mails dropped, 0 websites dropped.', $dry);
        self::assertStringContainsString('Descriptions: 2 section, 0 before facts, 0 long paragraphs, 0 none.', $dry);
        self::assertStringContainsString('Summaries: 0 sentence, 0 word cut, 2 whole, 0 Ambiente.', $dry);
        self::assertDirectoryDoesNotExist($this->content->path().'/venues');

        self::assertSame(Command::SUCCESS, $this->export());
        self::assertStringContainsString('Rows: 2. Mapped: 2 (2 created, 0 updated, 0 unchanged).', $this->exporter->getDisplay());

        self::assertSame([
            'name' => 'Landgasthof Beispielhof',
            'tier' => 'basic',
            'capacity' => ['max' => 120],
            'address' => ['canton' => 'zh'],
            'contact' => ['phone' => WixCsv::PHONE_E164, 'inquiry_email' => WixCsv::EMAIL, 'website' => WixCsv::WEBSITE],
            'translations' => ['de' => ['summary' => WixCsv::DESCRIPTION, 'description' => WixCsv::DESCRIPTION]],
        ], $this->yaml(self::key()));
    }

    public function test_liechtenstein_is_skipped_and_is_no_failure(): void
    {
        $this->csv([WixCsv::row(), WixCsv::row(['Region' => 'Liechtenstein', 'ID' => self::SECOND_ID, 'Telefon' => '+4233001122'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        self::assertStringContainsString('Rows: 2. Mapped: 1 (1 created, 0 updated, 0 unchanged). Skipped: 1. Unresolved: 0.', $this->exporter->getDisplay());
        self::assertCount(1, $this->folders());
    }

    public function test_a_region_without_a_canton_lands_in_the_translation(): void
    {
        $this->csv([WixCsv::row(['Region' => 'Schweizweit']), WixCsv::row(['Name' => 'Neuer Hof', 'ID' => self::SECOND_ID, 'Region' => 'Ein neues Gebiet'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        $first = $this->yaml(self::key());
        self::assertArrayNotHasKey('address', $first);
        self::assertSame('Schweizweit', self::path($first, 'translations', 'de')['region'] ?? null);
        self::assertSame('Ein neues Gebiet', self::path($this->yaml(self::key('Neuer Hof', self::SECOND_ID)), 'translations', 'de')['region'] ?? null);
    }

    public function test_an_alias_gives_a_canton_and_nothing_else_of_an_address(): void
    {
        $this->csv([WixCsv::row(['Region' => 'Rümlang'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        self::assertSame(['canton' => 'zh'], $this->yaml(self::key())['address']);
    }

    public function test_a_rerun_keeps_every_key_it_does_not_own(): void
    {
        $this->csv([WixCsv::row()]);
        $this->export();
        $overlaid = $this->yaml(self::key());
        $overlaid['tier'] = 'premium';
        $overlaid['price_level'] = 'medium';
        $overlaid['terms'] = ['building' => ['barn'], 'amenity' => ['parking']];
        $overlaid['main_terms'] = ['building' => 'barn'];
        $overlaid['party'] = ['allowed' => 'limited', 'has_curfew' => true, 'curfew_at' => '02:00'];
        $overlaid['photos'] = [['file' => 'aussen.jpg', 'alt' => ['de' => 'Aussen']]];
        $overlaid['capacity'] = ['max' => 120, 'seated' => 100];
        \assert(\is_array($overlaid['contact']) && \is_array($overlaid['translations']) && \is_array($overlaid['translations']['de']));
        $overlaid['contact']['instagram'] = 'https://www.instagram.com/beispielhof';
        $overlaid['translations']['de']['ambience'] = 'Ländlich';
        $overlaid['translations']['de']['opening_hours'] = 'Mai bis Oktober';
        $this->overwrite(self::key(), $overlaid);
        $this->csv([WixCsv::row(['Kapazität' => '150 Personen'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        $overlaid['capacity'] = ['max' => 150, 'seated' => 100];
        self::assertEquals($overlaid, $this->yaml(self::key()));
        self::assertStringContainsString('(0 created, 1 updated, 0 unchanged)', $this->exporter->getDisplay());
    }

    public function test_a_renamed_venue_keeps_its_folder(): void
    {
        $this->csv([WixCsv::row()]);
        $this->export();
        $this->csv([WixCsv::row(['Name' => 'Ganz Neuer Name'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        self::assertSame([self::key()], $this->folders());
        self::assertSame('Ganz Neuer Name', $this->yaml(self::key())['name']);
    }

    public function test_a_value_gone_from_the_csv_removes_its_path(): void
    {
        $this->csv([WixCsv::row()]);
        $this->export();
        $this->csv([WixCsv::row(['Telefon' => '', 'Website' => '', 'Email' => '', 'Region' => 'Schweizweit'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        $data = $this->yaml(self::key());
        self::assertArrayNotHasKey('contact', $data);
        self::assertArrayNotHasKey('address', $data);
    }

    public function test_two_folders_for_one_id_are_unresolved(): void
    {
        $this->csv([WixCsv::row(), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID])]);
        $this->export();
        mkdir($this->content->path().'/venues/anderer-name-1a2b3c4d');

        self::assertSame(Command::FAILURE, $this->export());

        self::assertStringContainsString('row 1: two folders end in the same ID', $this->exporter->getDisplay());
        self::assertStringContainsString('Unresolved: 1.', $this->exporter->getDisplay());
        self::assertStringContainsString('(0 created, 0 updated, 1 unchanged)', $this->exporter->getDisplay());
    }

    public function test_a_broken_venue_yaml_is_left_alone(): void
    {
        $this->csv([WixCsv::row()]);
        $this->export();
        $file = $this->file(self::key());
        file_put_contents($file, "name: Kaputt\n  tier: basic\n");

        self::assertSame(Command::FAILURE, $this->export());

        self::assertSame("name: Kaputt\n  tier: basic\n", file_get_contents($file));
        self::assertStringContainsString('venue.yaml cannot be read', $this->exporter->getDisplay());
    }

    public function test_a_missing_column_writes_nothing(): void
    {
        $this->csv([WixCsv::row()], array_values(array_diff(WixCsv::COLUMNS, ['Region'])));

        self::assertSame(Command::FAILURE, $this->export());

        self::assertStringContainsString('Region', $this->exporter->getDisplay());
        self::assertSame([], $this->folders());
    }

    public function test_an_unresolved_row_fails_the_run_and_the_others_are_written(): void
    {
        $this->csv([WixCsv::row(['Kapazität' => 'auf Anfrage']), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID])]);

        self::assertSame(Command::FAILURE, $this->export());

        self::assertStringContainsString('Unresolved: 1.', $this->exporter->getDisplay());
        self::assertSame([self::key('Scheune Zwei', self::SECOND_ID)], $this->folders());
    }

    public function test_stale_folders_are_reported_and_kept(): void
    {
        $this->csv([WixCsv::row(), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID])]);
        $this->export();
        $this->csv([WixCsv::row()]);
        mkdir($this->content->path().'/venues/handgemacht');

        self::assertSame(Command::SUCCESS, $this->export());

        $stale = self::key('Scheune Zwei', self::SECOND_ID);
        self::assertStringContainsString($stale.': stale', $this->exporter->getDisplay());
        self::assertStringContainsString('handgemacht: stale', $this->exporter->getDisplay());
        self::assertContains($stale, $this->folders());
    }

    public function test_a_shifted_row_writes_no_venues_directory(): void
    {
        $this->csv([WixCsv::row()]);
        file_put_contents($this->csv, "\"nur\",\"zwei\"\n", \FILE_APPEND);

        self::assertSame(Command::FAILURE, $this->export());

        self::assertDirectoryDoesNotExist($this->content->path().'/venues');
    }

    public function test_a_row_with_an_unreadable_venue_yaml_is_not_also_stale(): void
    {
        $this->csv([WixCsv::row()]);
        $this->export();
        file_put_contents($this->file(self::key()), "name: Kaputt\n  tier: basic\n");

        self::assertSame(Command::FAILURE, $this->export());

        self::assertStringContainsString('venue.yaml cannot be read', $this->exporter->getDisplay());
        self::assertStringNotContainsString('stale', $this->exporter->getDisplay());
    }

    public function test_the_old_folder_of_a_liechtenstein_row_gets_its_own_line(): void
    {
        $this->csv([WixCsv::row(), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID])]);
        $this->export();
        $this->csv([WixCsv::row(), WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID, 'Region' => 'Liechtenstein'])]);

        self::assertSame(Command::SUCCESS, $this->export());

        $key = self::key('Scheune Zwei', self::SECOND_ID);
        self::assertStringContainsString($key.': in the CSV but not in Switzerland; left alone', $this->exporter->getDisplay());
        self::assertStringNotContainsString($key.': stale', $this->exporter->getDisplay());
    }

    public function test_the_output_never_holds_a_contact_value(): void
    {
        $this->csv([
            WixCsv::row(),
            WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID, 'Telefon' => '365', 'Email' => 'falsch@adresse', 'Website' => 'www.beispielhof.example.ch']),
        ]);

        self::assertSame(Command::SUCCESS, $this->export());

        $display = $this->exporter->getDisplay();
        self::assertStringContainsString('Contact: 1 phone dropped, 0 phones empty, 1 e-mails dropped, 1 websites dropped.', $display);
        foreach ([WixCsv::PHONE, WixCsv::PHONE_E164, WixCsv::EMAIL, WixCsv::WEBSITE, '365', 'falsch@adresse', 'www.beispielhof'] as $value) {
            self::assertStringNotContainsString($value, $display);
        }
    }

    public function test_contact_person_and_instagram_are_never_written(): void
    {
        $this->csv([WixCsv::row(['Owner' => 'Frau Fiktiv'])]);

        $this->export();

        $contact = $this->yaml(self::key())['contact'];
        \assert(\is_array($contact));
        self::assertSame(['phone', 'inquiry_email', 'website'], array_keys($contact));
    }

    public function test_the_output_imports_and_a_second_run_changes_nothing(): void
    {
        $this->csv([
            WixCsv::row(),
            WixCsv::row(['Name' => 'Scheune Zwei', 'ID' => self::SECOND_ID, 'Region' => 'Schweizweit', 'Vorstellungstext' => WixCsv::richText()]),
        ]);
        $this->export();
        self::assertSame(Command::SUCCESS, $this->import(), $this->display());
        self::assertStringContainsString('2 created, 0 updated, 0 unchanged, 0 failed', $this->display());
        $venue = $this->venueRow(self::key());
        self::assertIsArray($venue);
        self::assertSame('zh', $venue['canton_code']);
        self::assertNull($venue['town_id']);

        self::assertSame(Command::SUCCESS, $this->export());
        self::assertStringContainsString('(0 created, 0 updated, 2 unchanged)', $this->exporter->getDisplay());

        $this->em->clear();
        $this->import();
        self::assertStringContainsString('0 created, 0 updated, 2 unchanged, 0 failed', $this->display());
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string>                $columns
     */
    private function csv(array $rows, array $columns = WixCsv::COLUMNS): void
    {
        WixCsv::write($this->csv, $rows, $columns);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function export(array $options = []): int
    {
        return $this->exporter->execute(['csv' => $this->csv, 'path' => $this->content->path(), ...$options]);
    }

    private static function key(string $name = 'Landgasthof Beispielhof', string $id = WixCsv::ID): string
    {
        return LegacyVenueText::key($name, substr($id, 0, 8));
    }

    private function file(string $key): string
    {
        return $this->content->path().'/venues/'.$key.'/venue.yaml';
    }

    /**
     * @return array<string, mixed>
     */
    private function yaml(string $key): array
    {
        $data = Yaml::parseFile($this->file($key));
        \assert(\is_array($data));

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function overwrite(string $key, array $data): void
    {
        file_put_contents($this->file($key), Yaml::dump($data, 8, 2));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<mixed>
     */
    private static function path(array $data, string $section, string $field): array
    {
        $parent = $data[$section] ?? null;
        \assert(\is_array($parent) && \is_array($parent[$field] ?? null));

        return $parent[$field];
    }

    /**
     * @return list<string>
     */
    private function folders(): array
    {
        $directory = $this->content->path().'/venues';

        return is_dir($directory) ? array_values(array_filter((array) scandir($directory), static fn (string|false $entry): bool => !\in_array($entry, ['.', '..', false], true))) : [];
    }
}
