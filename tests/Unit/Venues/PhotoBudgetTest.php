<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Venues\ValueObject\PhotoBudget;
use PHPUnit\Framework\TestCase;

final class PhotoBudgetTest extends TestCase
{
    public function test_the_gallery_is_cut_to_the_budget_and_takes_every_room_photo_with_it(): void
    {
        [$gallery, $rooms] = PhotoBudget::cut(['g1', 'g2', 'g3', 'g4'], ['a', 'b'], ['a' => 'a1', 'b' => 'b1'], 3, 2);

        self::assertSame(['g1', 'g2', 'g3'], $gallery);
        self::assertSame([], $rooms);
    }

    public function test_what_the_gallery_leaves_goes_to_the_first_photo_of_each_visible_room_in_room_order(): void
    {
        [$gallery, $rooms] = PhotoBudget::cut(['g1', 'g2'], ['a', 'b', 'c'], ['c' => 'c1', 'a' => 'a1', 'b' => 'b1'], 4, 3);

        self::assertSame(['g1', 'g2'], $gallery);
        self::assertSame(['a' => 'a1', 'b' => 'b1'], $rooms, 'room order, not the order of the photos, and the budget ends at four');
        self::assertSame(['a', 'b'], array_keys($rooms));
    }

    public function test_a_hidden_room_takes_its_photo_with_it(): void
    {
        [, $rooms] = PhotoBudget::cut([], ['a', 'b'], ['b' => 'b1'], 6, 1);

        self::assertSame([], $rooms, 'room b is the second room, and one room is public');
    }

    public function test_a_visible_room_without_a_photo_costs_no_budget(): void
    {
        [, $rooms] = PhotoBudget::cut(['g1'], ['a', 'b'], ['b' => 'b1'], 2, 2);

        self::assertSame(['b' => 'b1'], $rooms);
    }

    public function test_a_budget_of_zero_shows_nothing(): void
    {
        self::assertSame([[], []], PhotoBudget::cut(['g1'], ['a'], ['a' => 'a1'], 0, 5));
    }

    public function test_no_visible_room_means_no_room_photo(): void
    {
        self::assertSame([['g1'], []], PhotoBudget::cut(['g1'], ['a'], ['a' => 'a1'], 9, 0));
    }
}
