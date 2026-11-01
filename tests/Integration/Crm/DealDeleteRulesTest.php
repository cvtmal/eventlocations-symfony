<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\DeleteDeal;
use App\Crm\Entity\Deal;
use App\Factory\ActivityFactory;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\Command\DeleteVenue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * What the foreign keys of `crm_deals` and `crm_tasks` do when their parents go (D7, D8), through the real commands:
 * a venue leaves the deal standing, a deal takes its links and tasks along, and the tasks of #27 stay valid.
 */
final class DealDeleteRulesTest extends KernelTestCase
{
    use DispatchesCrmCommands;
    use Factories;
    use RollsBackDatabase;

    public function test_deleting_a_venue_keeps_the_deal_without_it(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['status' => VenueStatus::Unpublished, 'name' => 'Remise Weg']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $deal = DealFactory::createOne(['company' => $company, 'venue' => $venue]);
        $this->em->clear();

        $this->dispatch(new DeleteVenue($venue->number(), 'Remise Weg'));

        self::assertSame(
            ['venue_id' => null, 'company_id' => $company->id()],
            $this->connection->fetchAssociative('SELECT venue_id, company_id FROM crm_deals WHERE id = ?', [$deal->id()]),
        );
    }

    public function test_deleting_a_deal_cascades_its_tasks_and_links(): void
    {
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);
        $this->link($deal, $contact->id());
        $task = TaskFactory::createOne(['deal' => $deal]);
        $note = ActivityFactory::new()->onDeal($deal)->create();
        $this->em->clear();

        $this->dispatch(new DeleteDeal($deal->id()));

        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_deals WHERE id = ?', $deal->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_deal_contacts WHERE deal_id = ?', $deal->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activity_deals WHERE deal_id = ?', $deal->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_tasks WHERE id = ?', $task->id()));
        self::assertSame(0, $this->rows('SELECT count(*) FROM crm_activities WHERE id = ?', $note->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_contacts WHERE id = ?', $contact->id()));
        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_companies WHERE id = ?', $company->id()));
    }

    public function test_deleting_a_deal_leaves_its_renewal_standing(): void
    {
        $company = CompanyFactory::createOne();
        $old = DealFactory::createOne(['company' => $company]);
        $renewal = DealFactory::createOne(['company' => $company, 'renewedFrom' => $old]);
        $this->em->clear();

        $this->dispatch(new DeleteDeal($old->id()));

        self::assertSame(1, $this->rows('SELECT count(*) FROM crm_deals WHERE id = ? AND renewed_from_id IS NULL', $renewal->id()));
    }

    public function test_the_tasks_of_27_still_satisfy_the_old_check_and_old_code_can_insert(): void
    {
        $company = CompanyFactory::createOne();
        TaskFactory::createOne(['company' => $company]);
        TaskFactory::createOne(['contact' => ContactFactory::createOne()]);
        TaskFactory::createOne();

        // The statement old code sends during a deploy's sync: it names neither new column.
        $this->connection->executeStatement(
            'INSERT INTO crm_tasks (id, title, due_on, assignee_id, company_id) VALUES (?, ?, ?, ?, ?)',
            [EntityId::generate(), 'Vor dem Deploy', '2026-11-02', AdminUserFactory::createOne()->id(), $company->id()],
        );

        self::assertSame(4, $this->rows('SELECT count(*) FROM crm_tasks WHERE num_nonnulls(company_id, contact_id) <= 1 AND deal_id IS NULL AND NOT is_renewal'));
    }

    public function test_a_task_takes_one_object(): void
    {
        $this->expectException(\LogicException::class);

        TaskFactory::createOne(['company' => CompanyFactory::createOne(), 'deal' => DealFactory::createOne()]);
    }

    private function link(Deal $deal, string $contactId): void
    {
        $this->connection->executeStatement('INSERT INTO crm_deal_contacts (deal_id, contact_id) VALUES (?, ?)', [$deal->id(), $contactId]);
    }

    private function rows(string $sql, string ...$parameters): int
    {
        return (int) $this->connection->fetchOne($sql, array_values($parameters));
    }
}
