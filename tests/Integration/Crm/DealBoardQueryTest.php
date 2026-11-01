<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\DealBoard;
use App\Crm\ReadModel\DealBoardQuery;
use App\Crm\ReadModel\DealColumn;
use App\Crm\ReadModel\DealFilter;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The board's two statements (D16): capped cards, true totals under a filter, and a head without won and lost.
 */
final class DealBoardQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_every_stage_is_a_column_in_order(): void
    {
        self::assertSame(['new', 'contacted', 'offer', 'negotiation', 'won', 'lost'], array_map(static fn (DealColumn $column): string => $column->stage->code, $this->board()->columns));
    }

    public function test_a_card_holds_what_the_board_shows(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        DealFactory::new()->inStage('lost', new \DateTimeImmutable('2026-12-31 23:30:00 UTC'), LostReason::Budget)->create([
            'name' => 'Premium 2027',
            'company' => CompanyFactory::createOne(['name' => 'Seeblick Events']),
            'amountRappen' => 240000,
            'expectedCloseOn' => new \DateTimeImmutable('2026-11-15'),
            'owner' => $owner,
        ]);

        $card = $this->column('lost')->cards[0];

        self::assertSame(['Premium 2027', 'Seeblick Events', 240000, '2026-11-15', LostReason::Budget, 'Andrea Keller', 'AK'], [$card->name, $card->companyName, $card->amountRappen, $card->expectedCloseOn, $card->lostReason, $card->ownerName, $card->ownerInitials()]);
        self::assertSame('2027-01-01', $card->closedOn, 'The day of the close is Zurich\'s.');
    }

    public function test_an_open_column_shows_a_hundred_cards_and_tells_the_truth(): void
    {
        $company = CompanyFactory::createOne();
        DealFactory::createMany(DealBoardQuery::OPEN_CAP + 2, ['company' => $company, 'amountRappen' => 100]);

        $column = $this->column('new');

        self::assertCount(DealBoardQuery::OPEN_CAP, $column->cards);
        self::assertSame(DealBoardQuery::OPEN_CAP + 2, $column->count);
        self::assertSame((DealBoardQuery::OPEN_CAP + 2) * 100, $column->sumRappen);
    }

    public function test_a_closed_column_shows_the_twenty_newest(): void
    {
        $company = CompanyFactory::createOne();
        foreach (range(1, DealBoardQuery::CLOSED_CAP + 1) as $day) {
            DealFactory::new()->inStage('won', new \DateTimeImmutable(\sprintf('2026-10-%02d 12:00:00 UTC', $day)))->create(['company' => $company, 'name' => 'Tag '.$day]);
        }

        $column = $this->column('won');

        self::assertCount(DealBoardQuery::CLOSED_CAP, $column->cards);
        self::assertSame(DealBoardQuery::CLOSED_CAP + 1, $column->count);
        self::assertSame('Tag 21', $column->cards[0]->name);
        self::assertSame('Tag 2', $column->cards[DealBoardQuery::CLOSED_CAP - 1]->name);
    }

    public function test_cards_run_by_expected_close_then_name_with_no_date_last(): void
    {
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company, 'name' => 'Ohne']);
        DealFactory::createOne(['company' => $company, 'name' => 'Zweiter', 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);
        DealFactory::createOne(['company' => $company, 'name' => 'Erster', 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);
        DealFactory::createOne(['company' => $company, 'name' => 'Später', 'expectedCloseOn' => new \DateTimeImmutable('2026-12-01')]);

        self::assertSame(['Erster', 'Zweiter', 'Später', 'Ohne'], array_map(static fn ($card): string => $card->name, $this->column('new')->cards));
    }

    public function test_totals_leave_won_and_lost_out_of_open_and_count_a_missing_amount_as_zero(): void
    {
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company, 'amountRappen' => 100]);
        DealFactory::new()->inStage('offer')->create(['company' => $company, 'amountRappen' => 250]);
        DealFactory::new()->inStage('offer')->create(['company' => $company]);
        DealFactory::new()->inStage('won')->create(['company' => $company, 'amountRappen' => 9000]);
        DealFactory::new()->inStage('lost')->create(['company' => $company, 'amountRappen' => 7000]);

        $board = $this->board();

        self::assertSame(3, $board->openCount);
        self::assertSame(350, $board->openSumRappen);
        self::assertSame(250, $this->column('offer')->sumRappen);
        self::assertSame(2, $this->column('offer')->count);
    }

    public function test_a_sum_above_the_integer_range_of_a_float_stays_exact(): void
    {
        $company = CompanyFactory::createOne();
        DealFactory::createMany(3, ['company' => $company, 'amountRappen' => 9_999_999_999]);

        self::assertSame(29_999_999_997, $this->board()->openSumRappen);
    }

    public function test_the_filter_holds_for_cards_and_totals(): void
    {
        $company = CompanyFactory::createOne();
        $owner = AdminUserFactory::createOne();
        DealFactory::createOne(['company' => $company, 'name' => 'Treffer', 'owner' => $owner, 'amountRappen' => 100, 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);
        DealFactory::createOne(['company' => $company, 'owner' => $owner, 'amountRappen' => 200, 'expectedCloseOn' => new \DateTimeImmutable('2026-12-15')]);
        DealFactory::createOne(['company' => $company, 'amountRappen' => 400, 'expectedCloseOn' => new \DateTimeImmutable('2026-11-15')]);

        $board = $this->board(new DealFilter(owner: $owner->id(), month: '2026-11'));

        self::assertSame(1, $board->openCount);
        self::assertSame(100, $board->openSumRappen);
        self::assertSame(['Treffer'], array_map(static fn ($card): string => $card->name, $board->columns[0]->cards));
    }

    private function board(DealFilter $filter = new DealFilter()): DealBoard
    {
        $query = self::getContainer()->get(DealBoardQuery::class);
        $stages = self::getContainer()->get(DealStageQuery::class);
        self::assertInstanceOf(DealBoardQuery::class, $query);
        self::assertInstanceOf(DealStageQuery::class, $stages);

        return $query->board($stages->all(), $filter);
    }

    private function column(string $code): DealColumn
    {
        foreach ($this->board()->columns as $column) {
            if ($code === $column->stage->code) {
                return $column;
            }
        }

        self::fail('No column '.$code);
    }
}
