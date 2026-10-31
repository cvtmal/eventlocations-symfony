<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Extension;

use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\Extension\FooterNoteBlock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class ContentBlockRegistryTest extends TestCase
{
    public function test_all_is_sorted_by_key(): void
    {
        $registry = new ContentBlockRegistry([self::block('listing.intro'), new FooterNoteBlock(), self::block('a.first')]);

        self::assertSame(
            ['a.first', 'footer.note', 'listing.intro'],
            array_map(static fn (ContentBlockDefinition $block): string => $block->key(), $registry->all()),
        );
    }

    public function test_get_finds_a_block_by_key(): void
    {
        $footer = new FooterNoteBlock();
        $registry = new ContentBlockRegistry([$footer]);

        self::assertSame($footer, $registry->get('footer.note'));
        self::assertNull($registry->get('listing.intro'));
    }

    public function test_two_blocks_with_one_key_throw(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"footer.note"');

        new ContentBlockRegistry([new FooterNoteBlock(), self::block('footer.note')]);
    }

    public function test_no_blocks_give_an_empty_registry(): void
    {
        self::assertSame([], (new ContentBlockRegistry([]))->all());
    }

    private static function block(string $key): ContentBlockDefinition
    {
        return new class($key) implements ContentBlockDefinition {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): TranslatableInterface
            {
                return new TranslatableMessage('label');
            }

            public function placement(): TranslatableInterface
            {
                return new TranslatableMessage('placement');
            }

            public function maxLength(): int
            {
                return 100;
            }

            public function fallback(): ?TranslatableInterface
            {
                return null;
            }
        };
    }
}
