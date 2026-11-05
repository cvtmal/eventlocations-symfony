<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\ChangeDealLostReason;
use App\Crm\Command\ChangeDealProperty;
use App\Crm\Command\CreateDeal;
use App\Crm\Command\DeleteCompany;
use App\Crm\Command\DeleteDeal;
use App\Crm\Command\MoveDealStage;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Deal;
use App\Crm\Exception\CompanyHasDeals;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\LostReasonRequired;
use App\Crm\Exception\StaleProperty;
use App\Crm\Exception\UnknownDealStage;
use App\Crm\Exception\VenueNotLinked;
use App\Crm\ValueObject\DealProperty;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\Tier;
use Doctrine\Bundle\DoctrineBundle\Middleware\BacktraceDebugDataHolder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * CRM-7's writes through command.bus against app_test in a rolled-back transaction: the rows, the rules, the lock
 * order (D11), and the audit row, which names fields or stage codes only.
 */
final class DealCommandsTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_a_new_deal_starts_at_new_with_its_links(): void
    {
        $venue = self::venue();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $owner = AdminUserFactory::createOne();
        $id = EntityId::generate();

        $this->dispatch(new CreateDeal(
            $id,
            '  Premium 2027 ',
            $company->id(),
            $venue->id(),
            Tier::Premium,
            120050,
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2027-12-31'),
            new \DateTimeImmutable('2026-12-01'),
            $owner->id(),
        ));

        self::assertSame(
            [
                'name' => 'Premium 2027',
                'company_id' => $company->id(),
                'venue_id' => $venue->id(),
                'tier' => 'premium',
                'amount_rappen' => 120050,
                'contract_start_on' => '2027-01-01',
                'contract_end_on' => '2027-12-31',
                'expected_close_on' => '2026-12-01',
                'stage_code' => 'new',
                'owner_id' => $owner->id(),
                'lost_reason' => null,
                'closed_at' => null,
            ],
            $this->connection->fetchAssociative('SELECT name, company_id, venue_id, tier, amount_rappen, contract_start_on, contract_end_on, expected_close_on, stage_code, owner_id, lost_reason, closed_at FROM crm_deals WHERE id = ?', [$id]),
        );
        self::assertSame(
            [['command' => CreateDeal::class, 'changes' => ['fields' => ['name', 'venue', 'tier', 'amount', 'contract_start', 'contract_end', 'expected_close', 'owner']]]],
            $this->auditRows('deal', $id),
        );
    }

    public function test_a_new_deal_needs_a_name_and_a_company(): void
    {
        $company = CompanyFactory::createOne();

        self::assertTrue($this->failure(new CreateDeal(EntityId::generate(), '   ', $company->id()), InvalidPropertyValue::class)->isRequired());
        $this->failure(new CreateDeal(EntityId::generate(), 'Premium', EntityId::generate()), CompanyNotFound::class);
    }

    public function test_a_new_deal_takes_only_the_companys_own_venue(): void
    {
        $company = CompanyFactory::createOne();
        $other = CompanyFactory::new()->withVenues(self::venue())->create();
        $foreignVenue = (string) $this->connection->fetchOne('SELECT venue_id FROM crm_company_venues WHERE company_id = ?', [$other->id()]);

        $this->failure(new CreateDeal(EntityId::generate(), 'Premium', $company->id(), $foreignVenue), VenueNotLinked::class);
        $this->failure(new CreateDeal(EntityId::generate(), 'Premium', $company->id(), self::venue()->id()), VenueNotLinked::class);
        $this->failure(new CreateDeal(EntityId::generate(), 'Premium', $company->id(), 'keine-uuid'), VenueNotLinked::class);
        $this->em->clear();

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_deals WHERE company_id = ?', $company->id()));
    }

    public function test_a_contract_cannot_end_before_it_starts(): void
    {
        $this->failure(
            new CreateDeal(EntityId::generate(), 'Premium', CompanyFactory::createOne()->id(), contractStartOn: new \DateTimeImmutable('2027-01-02'), contractEndOn: new \DateTimeImmutable('2027-01-01')),
            ContractEndsBeforeStart::class,
        );

        $deal = DealFactory::createOne(['contractStartOn' => new \DateTimeImmutable('2027-01-02'), 'contractEndOn' => new \DateTimeImmutable('2027-12-31')]);
        $this->failure(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, '2027-01-01', '2027-12-31'), ContractEndsBeforeStart::class);
        $this->em->clear();
        $this->failure(new ChangeDealProperty($deal->id(), DealProperty::ContractStart, '2028-01-01', '2027-01-02'), ContractEndsBeforeStart::class);
        $this->em->clear();

        self::assertSame('2027-12-31', $this->connection->fetchOne('SELECT contract_end_on FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame('2028-01-01', $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::ContractEnd, '2028-01-01', '2027-12-31')));
    }

    /**
     * @return iterable<string, array{DealProperty, string, string, string}>
     */
    public static function edits(): iterable
    {
        yield 'name' => [DealProperty::Name, '  Top Premium ', 'Top Premium', 'name'];
        yield 'tier' => [DealProperty::Tier, 'top_premium', 'top_premium', 'tier'];
        yield 'amount as typed' => [DealProperty::Amount, "1'234.50", '123450', 'amount_rappen'];
        yield 'contract start' => [DealProperty::ContractStart, '2027-01-01', '2027-01-01', 'contract_start_on'];
        yield 'expected close' => [DealProperty::ExpectedClose, '2026-12-24', '2026-12-24', 'expected_close_on'];
    }

    #[DataProvider('edits')]
    public function test_an_edit_answers_the_stored_form(DealProperty $property, string $typed, string $stored, string $column): void
    {
        $deal = DealFactory::createOne(['name' => 'Premium']);
        $expected = DealProperty::Name === $property ? 'Premium' : null;

        self::assertSame($stored, $this->dispatch(new ChangeDealProperty($deal->id(), $property, $typed, $expected)));
        $this->em->clear();

        self::assertSame([['command' => ChangeDealProperty::class, 'changes' => ['field' => $property->value]]], $this->auditRows('deal', $deal->id()));
        self::assertSame($stored, (string) $this->connection->fetchOne('SELECT '.$column.' FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_a_stale_edit_is_refused_and_an_equal_value_is_a_no_op(): void
    {
        $deal = DealFactory::createOne(['name' => 'Premium', 'amountRappen' => 123450]);

        self::assertSame('name', $this->failure(new ChangeDealProperty($deal->id(), DealProperty::Name, 'Basic', 'Veraltet'), StaleProperty::class)->field);
        self::assertSame('123450', $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::Amount, "1'234.50", 'veraltet')));
        self::assertSame('Premium', $this->connection->fetchOne('SELECT name FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_a_field_can_be_emptied_but_the_name_cannot(): void
    {
        $deal = DealFactory::createOne(['name' => 'Premium', 'amountRappen' => 100]);

        self::assertNull($this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::Amount, ' ', '100')));
        self::assertTrue($this->failure(new ChangeDealProperty($deal->id(), DealProperty::Name, ' ', 'Premium'), InvalidPropertyValue::class)->isRequired());
    }

    /**
     * @return iterable<string, array{DealProperty, string}>
     */
    public static function unreadable(): iterable
    {
        yield 'three decimals' => [DealProperty::Amount, '12.345'];
        yield 'a negative amount' => [DealProperty::Amount, '-1'];
        yield 'above the limit' => [DealProperty::Amount, '100000000'];
        yield 'no date' => [DealProperty::ContractEnd, 'morgen'];
        yield 'the 31st of a short month' => [DealProperty::ExpectedClose, '2027-02-31'];
        yield 'unknown tier' => [DealProperty::Tier, 'gold'];
        yield 'unknown owner' => [DealProperty::Owner, '019a0000-0000-7000-8000-00000000c999'];
    }

    #[DataProvider('unreadable')]
    public function test_a_value_that_cannot_be_read_is_refused(DealProperty $property, string $value): void
    {
        $deal = DealFactory::createOne();

        self::assertFalse($this->failure(new ChangeDealProperty($deal->id(), $property, $value, null), InvalidPropertyValue::class)->isRequired());
    }

    public function test_the_venue_changes_only_to_one_of_the_companys(): void
    {
        $venue = self::venue();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $deal = DealFactory::createOne(['company' => $company]);

        $this->failure(new ChangeDealProperty($deal->id(), DealProperty::Venue, self::venue()->id(), null), VenueNotLinked::class);
        $this->em->clear();

        self::assertSame($venue->id(), $this->dispatch(new ChangeDealProperty($deal->id(), DealProperty::Venue, $venue->id(), null)));
    }

    public function test_a_move_writes_the_activity_and_the_audit_row_together(): void
    {
        $company = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company, 'name' => 'Premium 2027']);
        $actor = AdminUserFactory::createOne();

        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'new', $actor->id()));

        self::assertSame('offer', $this->connection->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertEquals([['command' => MoveDealStage::class, 'changes' => ['from' => 'new', 'to' => 'offer']]], $this->auditRows('deal', $deal->id()));

        $activity = $this->connection->fetchAssociative('SELECT id, type_code, actor_id, title, body, payload FROM crm_activities');
        self::assertIsArray($activity);
        self::assertSame(ActivityType::DEAL_STAGE_CHANGED, $activity['type_code']);
        self::assertSame($actor->id(), $activity['actor_id']);
        self::assertSame('Premium 2027', $activity['title']);
        self::assertNull($activity['body']);
        self::assertEquals(['deal_name' => 'Premium 2027', 'from' => 'new', 'to' => 'offer'], json_decode((string) $activity['payload'], true, flags: \JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_deals WHERE activity_id = ? AND deal_id = ?', $activity['id'], $deal->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activity_companies WHERE activity_id = ? AND company_id = ?', $activity['id'], $company->id()));
    }

    public function test_a_second_identical_move_adds_nothing(): void
    {
        $deal = DealFactory::createOne();
        $actor = AdminUserFactory::createOne();

        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'new', $actor->id()));
        // The second person loaded the card at `new` too and chose the same target: no conflict.
        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'new', $actor->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_move_from_a_stage_that_is_gone_is_stale(): void
    {
        $deal = DealFactory::new()->inStage('contacted')->create();

        self::assertSame('stage', $this->failure(new MoveDealStage($deal->id(), 'offer', 'new', AdminUserFactory::createOne()->id()), StaleProperty::class)->field);
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $deal = DealFactory::createOne();

        $this->failure(new MoveDealStage($deal->id(), 'pausiert', 'new', AdminUserFactory::createOne()->id()), UnknownDealStage::class);
    }

    public function test_a_lost_deal_needs_a_reason_and_keeps_it(): void
    {
        $deal = DealFactory::createOne();
        $actor = AdminUserFactory::createOne()->id();

        $this->failure(new MoveDealStage($deal->id(), 'lost', 'new', $actor), LostReasonRequired::class);
        $this->dispatch(new MoveDealStage($deal->id(), 'lost', 'new', $actor, LostReason::Budget));

        $row = $this->connection->fetchAssociative('SELECT stage_code, lost_reason, closed_at FROM crm_deals WHERE id = ?', [$deal->id()]);
        self::assertIsArray($row);
        self::assertSame(['lost', 'budget'], [$row['stage_code'], $row['lost_reason']]);
        self::assertNotNull($row['closed_at']);
    }

    public function test_a_lost_deal_can_change_its_reason_without_a_stage_entry(): void
    {
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Other)->create();

        $this->dispatch(new ChangeDealLostReason($deal->id(), LostReason::NoNeed));

        self::assertSame(['lost', 'no_need'], array_values((array) $this->connection->fetchAssociative('SELECT stage_code, lost_reason FROM crm_deals WHERE id = ?', [$deal->id()])));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities'));
    }

    public function test_a_lost_deal_cannot_drop_its_reason(): void
    {
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Other)->create();

        $this->failure(new ChangeDealLostReason($deal->id(), null), LostReasonRequired::class);
    }

    public function test_a_deal_that_left_lost_refuses_a_reason_as_stale(): void
    {
        $deal = DealFactory::new()->inStage('offer')->create();

        self::assertSame('lost_reason', $this->failure(new ChangeDealLostReason($deal->id(), LostReason::Budget), StaleProperty::class)->field);
    }

    public function test_reopening_clears_the_close_and_the_reason(): void
    {
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Competitor)->create();

        $this->dispatch(new MoveDealStage($deal->id(), 'negotiation', 'lost', AdminUserFactory::createOne()->id()));

        self::assertSame(
            ['stage_code' => 'negotiation', 'lost_reason' => null, 'closed_at' => null],
            $this->connection->fetchAssociative('SELECT stage_code, lost_reason, closed_at FROM crm_deals WHERE id = ?', [$deal->id()]),
        );
    }

    public function test_a_won_deal_is_stamped_closed_without_a_reason(): void
    {
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Other)->create();

        $this->dispatch(new MoveDealStage($deal->id(), 'won', 'lost', AdminUserFactory::createOne()->id()));

        $row = $this->connection->fetchAssociative('SELECT lost_reason, closed_at FROM crm_deals WHERE id = ?', [$deal->id()]);
        self::assertIsArray($row);
        self::assertNull($row['lost_reason']);
        self::assertNotNull($row['closed_at']);
    }

    public function test_deleting_a_deal_keeps_a_stage_change_on_the_company_and_says_so_in_the_audit(): void
    {
        $company = CompanyFactory::createOne();
        $deal = DealFactory::createOne(['company' => $company]);
        $this->dispatch(new MoveDealStage($deal->id(), 'offer', 'new', AdminUserFactory::createOne()->id()));
        $this->em->clear();

        $this->dispatch(new DeleteDeal($deal->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_activities a JOIN crm_activity_companies l ON l.activity_id = a.id WHERE l.company_id = ?', $company->id()));
        self::assertSame(['command' => DeleteDeal::class, 'changes' => ['action' => 'deleted']], $this->auditRows('deal', $deal->id())[1]);
    }

    public function test_an_unknown_deal_is_refused_by_every_command(): void
    {
        $id = EntityId::generate();
        $actor = AdminUserFactory::createOne()->id();

        foreach ([
            new ChangeDealProperty($id, DealProperty::Name, 'Basic', null),
            new MoveDealStage($id, 'won', 'new', $actor),
            new DeleteDeal($id),
            new DeleteDeal('keine-uuid'),
        ] as $command) {
            $this->failure($command, DealNotFound::class);
        }
    }

    public function test_every_deal_write_locks_the_company_before_the_deal(): void
    {
        $company = CompanyFactory::createOne();
        $actor = AdminUserFactory::createOne()->id();

        $commands = [
            static fn (Deal $deal): object => new ChangeDealProperty($deal->id(), DealProperty::Name, 'Neu', $deal->name()),
            static fn (Deal $deal): object => new MoveDealStage($deal->id(), 'won', 'new', $actor),
            static fn (Deal $deal): object => new DeleteDeal($deal->id()),
        ];
        foreach ($commands as $command) {
            $deal = DealFactory::createOne(['company' => $company]);
            $this->em->clear();
            self::assertSame(['crm_companies', 'crm_deals'], $this->lockOrder($command($deal)), $command($deal)::class);
            $this->em->clear();
        }
    }

    public function test_a_company_with_a_deal_is_not_deleted(): void
    {
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company]);
        $this->em->clear();

        $this->failure(new DeleteCompany($company->id()), CompanyHasDeals::class);

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $company->id()));
    }

    public function test_a_task_on_a_deal_goes_with_the_deal_only(): void
    {
        $company = CompanyFactory::createOne();
        $deal = DealFactory::createOne(['company' => $company]);
        $onCompany = TaskFactory::createOne(['company' => $company]);
        $onDeal = TaskFactory::createOne(['deal' => $deal]);
        $this->em->clear();

        $this->dispatch(new DeleteDeal($deal->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_tasks WHERE id = ?', $onCompany->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE id = ?', $onDeal->id()));
    }

    private static function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation(null)->create();
    }

    /**
     * The tables of the command's `FOR UPDATE` statements, company and deal only, in the order they ran.
     *
     * @return list<string>
     */
    private function lockOrder(object $command): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(BacktraceDebugDataHolder::class, $holder);
        $holder->reset();

        $this->dispatch($command);

        $tables = [];
        foreach ($holder->getData()['default'] ?? [] as $query) {
            if (str_contains((string) $query['sql'], 'FOR UPDATE') && 1 === preg_match('/FROM (crm_companies|crm_deals)\b/', (string) $query['sql'], $match)) {
                $tables[] = $match[1];
            }
        }

        return $tables;
    }

    private function rows(string $sql, mixed ...$parameters): int
    {
        return (int) $this->connection->fetchOne($sql, array_values($parameters));
    }
}
