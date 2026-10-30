<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Translation;

use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\TranslationJoin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationJoinTest extends TestCase
{
    public function test_parameters_fall_back_to_the_default_locale(): void
    {
        self::assertSame(
            ['i18n_locale' => 'fr', 'i18n_fallback_locale' => 'de'],
            $this->join()->parameters('fr'),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'table' => ['venue_translations; DROP', 'venue_id', 'v.id', 't'];
        yield 'alias' => ['venue_translations', 'venue_id', 'v.id', 't x'];
        yield 'owner id' => ['venue_translations', 'venue_id', 'v.id OR 1=1', 't'];
    }

    #[DataProvider('invalidIdentifiers')]
    public function test_interpolated_identifiers_are_validated(string $table, string $foreignKey, string $ownerIdSql, string $alias): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->join()->localized($table, $foreignKey, $ownerIdSql, $alias);
    }

    private function join(): TranslationJoin
    {
        return new TranslationJoin(new EnabledLocale(['de'], 'de'));
    }
}
