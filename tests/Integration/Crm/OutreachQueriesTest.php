<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\Command\SuppressCompany;
use App\Crm\Command\SuppressEmail;
use App\Crm\Entity\Company;
use App\Crm\ReadModel\CohortFunnelQuery;
use App\Crm\ReadModel\OutreachQuery;
use App\Crm\ReadModel\SuppressionQuery;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Crm\ValueObject\SuppressionReason;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\RollsBackDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Zenstruck\Foundry\Test\Factories;

/**
 * G11's read models against `app_test`: the one suppression rule (a row names the Location or one of its addresses),
 * the suppression commands' idempotence, the outreach fields and the cohort funnel's zeros.
 */
final class OutreachQueriesTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_a_location_is_suppressed_by_its_own_row_or_any_address_it_holds(): void
    {
        $plain = CompanyFactory::createOne();
        $byOwnEmail = CompanyFactory::new()->afterInstantiate(static fn (Company $company) => $company->changeEmail('info@remise.ch'))->create();
        $byContact = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $byContact, 'email' => 'mia@example.ch']);
        $this->dispatch(new SuppressEmail('INFO@remise.ch'));
        $this->dispatch(new SuppressEmail('mia@example.ch'));
        $queries = $this->service(SuppressionQuery::class);

        self::assertFalse($queries->isLocationSuppressed($plain->id()));
        self::assertTrue($queries->isLocationSuppressed($byOwnEmail->id()));
        self::assertTrue($queries->isLocationSuppressed($byContact->id()));
        self::assertTrue($queries->isAddressSuppressed(' Mia@Example.ch '));
        self::assertFalse($queries->isAddressSuppressed('tom@example.ch'));
        $state = $queries->stateOf($byContact->id());
        self::assertNotNull($state);
        self::assertFalse($state->whole);
        self::assertSame(SuppressionReason::Objection, $state->reason);
        self::assertNull($queries->stateOf($plain->id()));
        self::assertSame(2, $queries->count());
    }

    public function test_suppressing_twice_keeps_the_first_reason_and_adds_nothing(): void
    {
        $company = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $company, 'email' => 'mia@example.ch']);

        self::assertTrue($this->dispatch(new SuppressCompany($company->id(), SuppressionReason::Objection)));
        self::assertFalse($this->dispatch(new SuppressCompany($company->id(), SuppressionReason::OptOut)));
        self::assertFalse($this->dispatch(new SuppressEmail('mia@example.ch')));

        $state = $this->service(SuppressionQuery::class)->stateOf($company->id());
        self::assertNotNull($state);
        self::assertTrue($state->whole);
        self::assertSame(SuppressionReason::Objection, $state->reason);
        self::assertSame(['objection', 'objection'], $this->connection->fetchFirstColumn('SELECT reason FROM suppressions ORDER BY email NULLS FIRST'));
    }

    public function test_the_outreach_fields_and_campaigns_read_back(): void
    {
        $outreach = new Outreach('brief-2026-11', OutreachCohort::A, ContactChannel::Postal, sentOn: new \DateTimeImmutable('2026-11-10'));
        $company = CompanyFactory::new()->withVenue(VenueFactory::createOne())->withOutreach($outreach)->create();
        CompanyFactory::new()->withOutreach(new Outreach('brief-2026-11'))->create();
        $queries = $this->service(OutreachQuery::class);

        self::assertTrue($outreach->equals($queries->of($company->id()) ?? new Outreach()));
        $campaigns = $queries->campaigns();
        self::assertCount(1, $campaigns);
        self::assertSame(['brief-2026-11', 2, 1, 1], [$campaigns[0]->code, $campaigns[0]->locations, $campaigns[0]->printable, $campaigns[0]->unsent]);
    }

    public function test_the_cohort_funnel_lists_every_cohort_with_zeros(): void
    {
        $funnel = $this->service(CohortFunnelQuery::class)->funnel();

        self::assertSame(CohortFunnelQuery::STEPS, $funnel->steps);
        self::assertSame(OutreachCohort::cases(), array_map(static fn ($row) => $row->cohort, $funnel->rows));
        foreach ($funnel->rows as $row) {
            self::assertSame([0, 0, 0, 0], [$row->locations, $row->sent, $row->returned, $row->suppressed]);
            self::assertSame([0, 0, 0, 0, 0, 0], $row->steps);
        }
    }

    private function dispatch(object $command): mixed
    {
        $result = $this->service(MessageBusInterface::class)->dispatch($command)->last(HandledStamp::class)?->getResult();
        $this->em->clear();

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get(MessageBusInterface::class === $class ? 'command.bus' : $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
