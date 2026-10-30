<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The annotated example in docs/content-format.md is a valid venue.yaml.
 */
final class ContentFormatDocsTest extends TestCase
{
    public function test_the_example_validates_against_the_schema(): void
    {
        $markdown = (string) file_get_contents(\dirname(__DIR__, 3).'/docs/content-format.md');
        if (1 !== preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match)) {
            self::fail('docs/content-format.md has no ```yaml block');
        }

        $data = Yaml::parse($match[1], Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        self::assertInstanceOf(\stdClass::class, $data);

        self::assertSame([], ContentSchemaTest::schema()->validate($data));
    }

    public function test_the_schema_is_draft_07(): void
    {
        $schema = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/docs/content-format.schema.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);
    }
}
