<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\GrantorType;
use PHPUnit\Framework\TestCase;

/**
 * The person or process behind a grant or its revocation (G1): a type and an opaque reference
 * that fits `media_rights_grants.grantor_ref` (VARCHAR(64)).
 */
final class GrantorTest extends TestCase
{
    public function test_the_named_constructors_set_the_type(): void
    {
        $id = '019a0000-0000-7000-8000-000000000001';

        self::assertEquals(new Grantor(GrantorType::Manager, $id), Grantor::manager($id));
        self::assertEquals(new Grantor(GrantorType::Staff, $id), Grantor::staff($id));
        self::assertEquals(new Grantor(GrantorType::System, 'import'), Grantor::system('import'));
        self::assertSame(GrantorType::Staff, Grantor::staff($id)->type);
        self::assertSame($id, Grantor::staff($id)->ref);
    }

    public function test_a_reference_has_one_to_sixty_four_characters(): void
    {
        self::assertSame('x', Grantor::system('x')->ref);
        self::assertSame(64, mb_strlen(Grantor::system(str_repeat('x', 64))->ref));
        // The column counts characters, not bytes.
        self::assertSame(64, mb_strlen(Grantor::system(str_repeat('ä', 64))->ref));
    }

    public function test_an_empty_reference_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Grantor::system('');
    }

    public function test_a_reference_of_sixty_five_characters_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Grantor::manager(str_repeat('x', 65));
    }
}
