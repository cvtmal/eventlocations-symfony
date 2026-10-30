<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Search\ReadModel\KnownTermsQuery;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KnownTermsQueryTest extends KernelTestCase
{
    private KnownTermsQuery $query;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->query = new KnownTermsQuery($connection);
    }

    public function test_loads_the_seeded_vocabulary(): void
    {
        $terms = $this->query->all();

        self::assertSame(146, $terms->count());
        self::assertTrue($terms->has(TermGroup::Setting, 'lake'));
        self::assertTrue($terms->has(TermGroup::Infrastructure, 'wifi'));
        self::assertFalse($terms->has(TermGroup::Infrastructure, 'lake'));
        self::assertFalse($terms->has(TermGroup::Setting, 'unknown'));
    }

    public function test_is_memoized_until_reset(): void
    {
        $first = $this->query->all();

        self::assertSame($first, $this->query->all());

        $this->query->reset();
        self::assertNotSame($first, $this->query->all());
        self::assertEquals($first, $this->query->all());
    }
}
