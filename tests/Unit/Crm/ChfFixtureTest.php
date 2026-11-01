<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\ValueObject\Chf;
use PHPUnit\Framework\TestCase;

/**
 * One set of amounts proves the text on both sides (N6): `admin-ui/tests/crm/money.test.js` reads the same file, and the
 * group character is the one `swiss_number` prints (Probe 1).
 */
final class ChfFixtureTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../../admin-ui/tests/fixtures/chf.json';

    public function test_php_formats_every_vector(): void
    {
        foreach ($this->fixture()['vectors'] as $vector) {
            self::assertSame($vector['text'], Chf::format($vector['rappen']));
        }
    }

    public function test_php_reads_back_what_it_wrote(): void
    {
        foreach ($this->fixture()['vectors'] as $vector) {
            self::assertSame($vector['rappen'], Chf::parse($vector['text']));
        }
    }

    public function test_the_group_character_is_the_one_of_swiss_number(): void
    {
        $formatted = new \NumberFormatter('de_CH', \NumberFormatter::DECIMAL)->format(1234567);

        self::assertSame(\sprintf('1%1$s234%1$s567', $this->fixture()['group']), $formatted);
    }

    /**
     * @return array{group: string, vectors: list<array{rappen: int, text: string}>}
     */
    private function fixture(): array
    {
        /** @var array{group: string, vectors: list<array{rappen: int, text: string}>} $fixture */
        $fixture = json_decode((string) file_get_contents(self::VECTORS), true, flags: \JSON_THROW_ON_ERROR);

        return $fixture;
    }
}
