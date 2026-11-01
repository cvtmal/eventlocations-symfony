<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\RenewDeal;
use App\Crm\Entity\Deal;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\DealNotRenewable;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Verlängern" (CRM-8, D21, N12): what the copy takes and leaves, the dates, the old renewal task, and that a deal is
 * renewed once.
 */
final class RenewDealTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_it_copies_the_deal_into_the_next_period(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $owner = AdminUserFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $old = DealFactory::new()->inStage('won')->create([
            'name' => 'Premium 2026',
            'company' => $company,
            'venue' => $venue,
            'tier' => Tier::Premium,
            'amountRappen' => 240000,
            'owner' => $owner,
            'contractStartOn' => new \DateTimeImmutable('2026-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2026-12-31'),
        ]);
        $this->link($old, $contact->id());
        $id = EntityId::generate();

        $this->dispatch(new RenewDeal($id, $old->id()));

        self::assertSame([
            'name' => 'Verlängerung 2027',
            'company_id' => $company->id(),
            'venue_id' => $venue->id(),
            'tier' => 'premium',
            'amount_rappen' => 240000,
            'owner_id' => $owner->id(),
            'stage_code' => 'new',
            'contract_start_on' => '2027-01-01',
            'contract_end_on' => '2027-12-31',
            'expected_close_on' => '2026-12-31',
            'renewed_from_id' => $old->id(),
            'closed_at' => null,
            'lost_reason' => null,
        ], $this->connection->fetchAssociative(
            'SELECT name, company_id, venue_id, tier, amount_rappen, owner_id, stage_code, contract_start_on, contract_end_on, expected_close_on, renewed_from_id, closed_at, lost_reason FROM crm_deals WHERE id = ?',
            [$id],
        ));
        self::assertSame([$contact->id()], $this->connection->fetchFirstColumn('SELECT contact_id FROM crm_deal_contacts WHERE deal_id = ?', [$id]));
        self::assertSame([['command' => RenewDeal::class, 'changes' => ['renewed_from' => $old->id()]]], $this->auditRows('deal', $id));
    }

    public function test_the_old_deal_stays_as_it_was(): void
    {
        $old = $this->won();
        $before = $this->connection->fetchAssociative('SELECT stage_code, closed_at, contract_end_on FROM crm_deals WHERE id = ?', [$old->id()]);

        $this->dispatch(new RenewDeal(EntityId::generate(), $old->id()));

        self::assertSame($before, $this->connection->fetchAssociative('SELECT stage_code, closed_at, contract_end_on FROM crm_deals WHERE id = ?', [$old->id()]));
    }

    public function test_whole_years_survive_a_leap_year(): void
    {
        $old = $this->won(['contractStartOn' => new \DateTimeImmutable('2026-03-01'), 'contractEndOn' => new \DateTimeImmutable('2027-02-28')]);
        $id = EntityId::generate();

        $this->dispatch(new RenewDeal($id, $old->id()));

        self::assertSame(
            ['contract_start_on' => '2027-03-01', 'contract_end_on' => '2028-02-29', 'name' => 'Verlängerung 2028'],
            $this->connection->fetchAssociative('SELECT contract_start_on, contract_end_on, name FROM crm_deals WHERE id = ?', [$id]),
        );
    }

    public function test_it_completes_the_old_open_renewal_task(): void
    {
        $old = $this->won();
        $task = TaskFactory::createOne(['deal' => $old, 'isRenewal' => true]);
        $other = TaskFactory::createOne(['deal' => $old]);

        $this->dispatch(new RenewDeal(EntityId::generate(), $old->id()));

        self::assertNotNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks WHERE id = ?', [$task->id()]));
        self::assertNull($this->connection->fetchOne('SELECT done_at FROM crm_tasks WHERE id = ?', [$other->id()]));
    }

    public function test_a_task_already_done_keeps_its_day(): void
    {
        $old = $this->won();
        $task = TaskFactory::createOne(['deal' => $old, 'isRenewal' => true]);
        $this->connection->executeStatement("UPDATE crm_tasks SET done_at = '2026-10-01 09:00:00+00' WHERE id = ?", [$task->id()]);
        $this->em->clear();

        $this->dispatch(new RenewDeal(EntityId::generate(), $old->id()));

        self::assertSame('2026-10-01 09:00:00+00', (string) $this->connection->fetchOne("SELECT to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') FROM crm_tasks WHERE id = ?", [$task->id()]));
    }

    public function test_a_second_renew_is_a_domain_error(): void
    {
        $old = $this->won();
        $this->dispatch(new RenewDeal(EntityId::generate(), $old->id()));

        $this->failure(new RenewDeal(EntityId::generate(), $old->id()), DealNotRenewable::class);

        self::assertSame(2, $this->rows('SELECT count(*) FROM crm_deals'));
    }

    public function test_only_a_won_deal_with_a_contract_end_renews(): void
    {
        $open = DealFactory::new()->inStage('offer')->create(['contractEndOn' => new \DateTimeImmutable('2026-12-31')]);
        $lost = DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create(['contractEndOn' => new \DateTimeImmutable('2026-12-31')]);
        $noEnd = DealFactory::new()->inStage('won')->create();

        foreach ([$open, $lost, $noEnd] as $deal) {
            $this->failure(new RenewDeal(EntityId::generate(), $deal->id()), DealNotRenewable::class);
        }
    }

    public function test_an_unknown_deal_is_a_domain_error(): void
    {
        $this->failure(new RenewDeal(EntityId::generate(), '019a0000-0000-7000-8000-000000000000'), DealNotFound::class);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function won(array $attributes = []): Deal
    {
        return DealFactory::new()->inStage('won')->create($attributes + [
            'contractStartOn' => new \DateTimeImmutable('2026-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2026-12-31'),
        ]);
    }

    private function link(Deal $deal, string $contactId): void
    {
        $this->connection->executeStatement('INSERT INTO crm_deal_contacts (deal_id, contact_id) VALUES (?, ?)', [$deal->id(), $contactId]);
    }
}
