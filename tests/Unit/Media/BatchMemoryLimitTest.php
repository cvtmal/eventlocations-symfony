<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Media\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;

final class BatchMemoryLimitTest extends TestCase
{
    private string $limit;

    protected function setUp(): void
    {
        $this->limit = (string) \ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->limit);
    }

    public function test_256m_is_raised_to_512m(): void
    {
        ini_set('memory_limit', '256M');

        self::assertTrue(ImageVariantGenerator::raiseBatchMemoryLimit());

        self::assertSame('512M', \ini_get('memory_limit'));
    }

    public function test_a_higher_limit_stays(): void
    {
        ini_set('memory_limit', '1G');

        self::assertTrue(ImageVariantGenerator::raiseBatchMemoryLimit());

        self::assertSame('1G', \ini_get('memory_limit'));
    }

    public function test_no_limit_stays(): void
    {
        ini_set('memory_limit', '-1');

        self::assertTrue(ImageVariantGenerator::raiseBatchMemoryLimit());

        self::assertSame('-1', \ini_get('memory_limit'));
    }
}
