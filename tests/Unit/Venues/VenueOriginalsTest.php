<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\VenueOriginals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F14: the withdrawal deletes what VenueOriginals names, so a key that is no content key never names a directory.
 */
final class VenueOriginalsTest extends TestCase
{
    public function test_a_venue_has_its_content_directory_and_its_upload_directory(): void
    {
        self::assertSame('content/remise-am-bach', VenueOriginals::contentDirectory('remise-am-bach'));
        self::assertSame(['content/remise-am-bach', 'cms/7'], VenueOriginals::directories(7, 'remise-am-bach'));
        self::assertSame(['cms/7'], VenueOriginals::directories(7, null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noContentKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'parent' => ['../x'];
        yield 'nested' => ['a/b'];
        yield 'dot' => ['.'];
        yield 'upper case' => ['Remise'];
        yield 'too long' => [str_repeat('a', 101)];
    }

    #[DataProvider('noContentKeys')]
    public function test_a_key_that_is_no_content_key_throws(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VenueOriginals::contentDirectory($key);
    }

    #[DataProvider('noContentKeys')]
    public function test_the_directories_of_a_venue_with_no_content_key_throw(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VenueOriginals::directories(7, $key);
    }
}
