<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Seo\TitleChain;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TitleChainTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function fits(): iterable
    {
        yield '23 characters fit the long form' => [23, ' in Zürich mieten | Hochzeitslocation'];
        yield '24 characters fall to the town form' => [24, ' in Zürich | Hochzeitslocation'];
        yield '30 characters still fit the town form' => [30, ' in Zürich | Hochzeitslocation'];
        yield '31 characters fall to the name' => [31, ' | Hochzeitslocation'];
        yield '40 characters still fit the name' => [40, ' | Hochzeitslocation'];
    }

    #[DataProvider('fits')]
    public function test_the_first_pattern_that_fits_wins(int $length, string $suffix): void
    {
        $name = str_repeat('x', $length);

        $title = $this->chain()->fit($this->townPatterns($name), $name);

        self::assertSame($name.$suffix, $title);
        self::assertLessThanOrEqual(TitleChain::MAX, mb_strlen($title));
    }

    public function test_a_long_name_is_cut_at_a_word_and_keeps_the_suffix(): void
    {
        $name = str_repeat('Wort ', 8).'Ende und noch mehr';

        $title = $this->chain()->fit($this->townPatterns($name), $name);

        self::assertLessThanOrEqual(TitleChain::MAX, mb_strlen($title));
        self::assertSame('Wort Wort Wort Wort Wort Wort Wort Wort… | Hochzeitslocation', $title);
    }

    public function test_a_name_of_41_characters_is_cut(): void
    {
        $name = str_repeat('x', 19).' '.str_repeat('y', 21);

        $title = $this->chain()->fit($this->townPatterns($name), $name);

        self::assertSame(str_repeat('x', 19).'… | Hochzeitslocation', $title);
    }

    public function test_one_endless_word_is_cut_at_the_limit(): void
    {
        $name = str_repeat('ü', 150);

        $title = $this->chain()->fit($this->townPatterns($name), $name);

        self::assertSame(TitleChain::MAX, mb_strlen($title));
        self::assertStringEndsWith('ü… | Hochzeitslocation', $title);
    }

    /**
     * @return list<array{string, array<string, string>}>
     */
    private function townPatterns(string $name): array
    {
        return [
            ['venue.title_town_long', ['name' => $name, 'town' => 'Zürich']],
            ['venue.title_town', ['name' => $name, 'town' => 'Zürich']],
            ['venue.title_name', ['name' => $name]],
        ];
    }

    private function chain(): TitleChain
    {
        $chain = self::getContainer()->get(TitleChain::class);
        self::assertInstanceOf(TitleChain::class, $chain);

        return $chain;
    }
}
