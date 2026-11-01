<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeDealProperty;
use App\Crm\Command\DeleteDeal;
use App\Crm\Command\MoveDealStage;
use App\Crm\CompanyStageSync;
use App\Crm\Entity\Company;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The derived company stage (S13, D9, D10, L2), its one writer: every row of D9's table, its corner, and the writes
 * that reach it (N11).
 */
final class CompanyStageSyncTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    private const string TODAY = '2026-12-15';

    /**
     * @return iterable<string, array{CompanyStage, list<?string>, CompanyStage}>
     */
    public static function wonDeals(): iterable
    {
        yield 'a contract without an end' => [CompanyStage::Prospect, [null], CompanyStage::Customer];
        yield 'a contract ending today' => [CompanyStage::Prospect, [self::TODAY], CompanyStage::Customer];
        yield 'a contract ending later' => [CompanyStage::FormerCustomer, ['2027-06-30'], CompanyStage::Customer];
        yield 'a contract that ended yesterday' => [CompanyStage::Customer, ['2026-12-14'], CompanyStage::FormerCustomer];
        yield 'all contracts ended' => [CompanyStage::Prospect, ['2025-12-31', '2024-12-31'], CompanyStage::FormerCustomer];
        yield 'one of two still running' => [CompanyStage::Prospect, ['2025-12-31', '2027-01-01'], CompanyStage::Customer];
    }

    /**
     * @param list<?string> $ends
     */
    #[DataProvider('wonDeals')]
    public function test_a_won_deal_decides_the_stage(CompanyStage $before, array $ends, CompanyStage $after): void
    {
        $company = CompanyFactory::new()->inStage($before)->create();
        foreach ($ends as $end) {
            DealFactory::new()->inStage('won')->create(['company' => $company, 'contractEndOn' => null === $end ? null : new \DateTimeImmutable($end)]);
        }

        self::assertSame(1, $this->sync()->syncAll());

        self::assertSame($after->value, $this->stage($company));
    }

    /**
     * @return iterable<string, array{CompanyStage, ?string}>
     */
    public static function noWonDeal(): iterable
    {
        yield 'no deal, prospect' => [CompanyStage::Prospect, null];
        yield 'no deal, set by hand to customer' => [CompanyStage::Customer, null];
        yield 'no deal, set by hand to former customer' => [CompanyStage::FormerCustomer, null];
        yield 'an open upsell keeps a hand-set customer' => [CompanyStage::Customer, 'negotiation'];
        yield 'an open deal keeps a prospect' => [CompanyStage::Prospect, 'offer'];
        yield 'a lost deal keeps a hand-set customer' => [CompanyStage::Customer, 'lost'];
    }

    #[DataProvider('noWonDeal')]
    public function test_without_a_won_deal_the_stage_stays_what_it_was(CompanyStage $stage, ?string $dealStage): void
    {
        $company = CompanyFactory::new()->inStage($stage)->create();
        if (null !== $dealStage) {
            DealFactory::new()->inStage($dealStage)->create(['company' => $company]);
        }

        self::assertSame(0, $this->sync()->syncAll());

        self::assertSame($stage->value, $this->stage($company));
    }

    public function test_it_leaves_updated_at_alone_and_a_correct_stage_unwritten(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Prospect)->create();
        DealFactory::new()->inStage('won')->create(['company' => $company]);
        $this->connection->executeStatement("UPDATE crm_companies SET updated_at = '2026-01-01 12:00:00+00' WHERE id = ?", [$company->id()]);

        self::assertSame(1, $this->sync()->syncAll());
        self::assertSame(0, $this->sync()->syncAll());

        self::assertStringStartsWith('2026-01-01', (string) $this->connection->fetchOne('SELECT updated_at FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_one_company_is_synced_alone(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Prospect)->create();
        $other = CompanyFactory::new()->inStage(CompanyStage::Prospect)->create();
        DealFactory::new()->inStage('won')->create(['company' => $company]);
        DealFactory::new()->inStage('won')->create(['company' => $other]);

        $this->sync()->syncCompany($company->id());

        self::assertSame('customer', $this->stage($company));
        self::assertSame('prospect', $this->stage($other));
    }

    public function test_today_is_the_zurich_calendar_day(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        DealFactory::new()->inStage('won')->create(['company' => $company, 'contractEndOn' => new \DateTimeImmutable('2026-12-31')]);

        // 23:30 UTC on the 31st is already the 1st in Zurich.
        (new CompanyStageSync($this->connection, new MockClock('2026-12-31 23:30:00 UTC')))->syncAll();

        self::assertSame('former_customer', $this->stage($company));
    }

    public function test_a_won_deal_leaving_the_stage_falls_back_to_prospect(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Prospect)->create();
        $deal = DealFactory::createOne(['company' => $company]);
        $actor = AdminUserFactory::createOne()->id();

        $this->dispatch(new MoveDealStage($deal->id(), 'won', 'new', $actor));
        self::assertSame('customer', $this->stage($company));

        $this->dispatch(new MoveDealStage($deal->id(), 'lost', 'won', $actor, LostReason::Budget));
        self::assertSame('prospect', $this->stage($company));
    }

    public function test_a_second_won_deal_keeps_the_company_a_customer_when_one_leaves(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        $deal = DealFactory::new()->inStage('won')->create(['company' => $company]);
        DealFactory::new()->inStage('won')->create(['company' => $company]);

        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'won', AdminUserFactory::createOne()->id()));

        self::assertSame('customer', $this->stage($company));
    }

    public function test_deleting_the_last_won_deal_falls_back_to_prospect(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        $deal = DealFactory::new()->inStage('won')->create(['company' => $company]);
        $this->em->clear();

        $this->dispatch(new DeleteDeal($deal->id()));

        self::assertSame('prospect', $this->stage($company));
    }

    public function test_deleting_an_open_deal_keeps_a_hand_set_stage(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::FormerCustomer)->create();
        $deal = DealFactory::createOne(['company' => $company]);
        $this->em->clear();

        $this->dispatch(new DeleteDeal($deal->id()));

        self::assertSame('former_customer', $this->stage($company));
    }

    public function test_moving_a_contract_end_on_a_won_deal_moves_the_stage(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        $deal = DealFactory::new()->inStage('won')->create(['company' => $company]);

        $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, '2020-12-31', null));
        self::assertSame('former_customer', $this->stage($company));

        $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, null, '2020-12-31'));
        self::assertSame('customer', $this->stage($company));
    }

    public function test_an_edit_on_an_open_deal_leaves_the_stage_alone(): void
    {
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        $deal = DealFactory::createOne(['company' => $company]);

        $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, '2020-12-31', null));

        self::assertSame('customer', $this->stage($company));
    }

    private function sync(): CompanyStageSync
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return new CompanyStageSync($connection, new MockClock(self::TODAY.' 12:00:00 Europe/Zurich'));
    }

    private function stage(Company $company): string
    {
        return (string) $this->connection->fetchOne('SELECT stage FROM crm_companies WHERE id = ?', [$company->id()]);
    }
}
