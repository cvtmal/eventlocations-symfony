<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Media\MediaFiles;
use App\Venues\ContentYamlFile;
use App\Venues\Exception\InvalidContentFolder;
use PHPUnit\Framework\TestCase;

final class ContentYamlFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \dirname(__DIR__, 3).'/var/test-content-yaml-'.uniqid();
    }

    protected function tearDown(): void
    {
        MediaFiles::removeDirectory($this->directory);
    }

    public function test_setting_null_removes_the_field_and_an_emptied_section(): void
    {
        $document = new \stdClass();
        ContentYamlFile::set($document, 'faq', 'own_catering', 'allowed');
        ContentYamlFile::set($document, 'faq', 'daylight', false);

        ContentYamlFile::set($document, 'faq', 'own_catering', null);
        self::assertSame(['daylight' => false], get_object_vars($document->faq));

        ContentYamlFile::set($document, 'faq', 'daylight', null);
        self::assertObjectNotHasProperty('faq', $document);

        ContentYamlFile::set($document, 'absent', 'field', null);
        self::assertSame([], get_object_vars($document));
    }

    public function test_a_written_file_reads_back_to_the_same_bytes(): void
    {
        $file = $this->directory.'/venue/venue.yaml';
        $document = new \stdClass();
        $document->name = 'Beispielhof';
        ContentYamlFile::set($document, 'terms', 'catering', ['vegan', 'vegetarian']);
        ContentYamlFile::set($document, 'address', 'zip', '8001');
        ContentYamlFile::set($document, 'terms', 'amenity', []);
        $document->description = "Zeile eins\nZeile zwei";

        ContentYamlFile::replace($file, ContentYamlFile::dump($document));
        $read = ContentYamlFile::read($file, 'venue');

        self::assertNotNull($read);
        self::assertSame((string) file_get_contents($file), ContentYamlFile::dump($read));
        self::assertStringContainsString("zip: '8001'", (string) file_get_contents($file));
        self::assertSame(['venue.yaml'], array_values(array_diff((array) scandir(\dirname($file)), ['.', '..'])));
    }

    public function test_a_missing_file_is_null(): void
    {
        self::assertNull(ContentYamlFile::read($this->directory.'/nothing.yaml', 'nothing'));
    }

    public function test_a_section_that_is_not_a_map_makes_the_file_unreadable(): void
    {
        $file = $this->directory.'/venue.yaml';
        ContentYamlFile::replace($file, "translations:\n  de: text\n");

        self::assertNotNull(ContentYamlFile::read($file, 'venue', ['translations']));

        $this->expectException(InvalidContentFolder::class);
        $this->expectExceptionMessage('venue: venue.yaml cannot be read');

        ContentYamlFile::read($file, 'venue', ['translations', 'translations.de']);
    }

    public function test_broken_yaml_makes_the_file_unreadable(): void
    {
        $file = $this->directory.'/venue.yaml';
        ContentYamlFile::replace($file, "name: Kaputt\n  tier: basic\n");

        $this->expectException(InvalidContentFolder::class);

        ContentYamlFile::read($file, 'venue');
    }
}
