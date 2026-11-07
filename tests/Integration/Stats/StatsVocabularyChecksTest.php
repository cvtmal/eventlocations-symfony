<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stats;

use App\Shared\Funnel\FunnelEvent;
use App\Stats\ValueObject\ClickKind;
use App\Stats\ValueObject\EventType;
use App\Stats\ValueObject\Metric;
use App\Stats\ValueObject\PageType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The sibling of VocabularyChecksTest for the Stats tables (#47): every enum in a CHECK-constrained column
 * has exactly the values of the CHECK's IN list, in the same order.
 */
final class StatsVocabularyChecksTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function checks(): iterable
    {
        yield 'event type' => ['chk_stats_events_type', EventType::class];
        yield 'click kind' => ['chk_stats_events_kind', ClickKind::class];
        yield 'page type' => ['chk_stats_events_page', PageType::class];
        yield 'metric' => ['chk_stats_daily_metric', Metric::class];
        yield 'funnel event' => ['chk_funnel_events_event', FunnelEvent::class];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('checks')]
    public function test_check_lists_the_enum_values(string $constraint, string $enum): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $definition = $connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
        self::assertIsString($definition, $constraint);
        preg_match_all("/'([^']*)'::/", $definition, $matches);

        self::assertSame(array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()), $matches[1]);
    }
}
