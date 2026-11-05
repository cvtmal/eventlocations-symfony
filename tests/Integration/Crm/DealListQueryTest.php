<?php

declare(strict_types=1);

namespace App\Tests\Integration\Crm;

use App\Crm\ReadModel\DealFilter;
use App\Crm\ReadModel\DealFormQuery;
use App\Crm\ReadModel\DealListQuery;
use App\Crm\ReadModel\DealSort;
use App\Crm\ReadModel\SortDirection;
use App\Crm\ValueObject\LostReason;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\RollsBackDatabase;
use App\Venues\ValueObject\Tier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The deal list's and the create form's statements against Postgres (D12, N5).
 */
final class DealListQueryTest extends KernelTestCase
{
    use Factories;
    use RollsBackDatabase;

    public function test_it_reads_a_row(): void
    {
        $owner = AdminUserFactory::createOne(['displayName' => 'Andrea Keller']);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $deal = DealFactory::new()->inStage('offer')->create([
            'name' => 'Premium 2027',
            'company' => $company,
            'tier' => Tier::Premium,
            'amountRappen' => 9_999_999_999,
            'expectedCloseOn' => new \DateTimeImmutable('2026-11-15'),
            'owner' => $owner,
        ]);

        $page = $this->query()->search(new DealFilter(), DealSort::DEFAULT, SortDirection::Asc, 1);

        self::assertSame(1, $page->total);
        $row = $page->rows[0];
        self::assertSame([$deal->id(), 'Premium 2027', $company->id(), 'Seeblick Events', 'offer', Tier::Premium, 9_999_999_999, 'Andrea Keller'], [$row->id, $row->name, $row->companyId, $row->companyName, $row->stage, $row->tier, $row->amountRappen, $row->ownerName]);
        self::assertSame('2026-11-15', $row->expectedCloseOn?->format('Y-m-d'));
    }

    public function test_the_month_is_the_month_of_the_expected_close(): void
    {
        DealFactory::createOne(['name' => 'Anfang', 'expectedCloseOn' => new \DateTimeImmutable('2026-12-01')]);
        DealFactory::createOne(['name' => 'Ende', 'expectedCloseOn' => new \DateTimeImmutable('2026-12-31')]);
        DealFactory::createOne(['name' => 'Davor', 'expectedCloseOn' => new \DateTimeImmutable('2026-11-30')]);
        DealFactory::createOne(['name' => 'Ohne']);

        $rows = $this->query()->search(new DealFilter(month: '2026-12'), DealSort::Name, SortDirection::Asc, 1)->rows;

        self::assertSame(['Anfang', 'Ende'], array_map(static fn ($row): string => $row->name, $rows));
        self::assertSame(['2026-11', '2026-12'], $this->query()->months());
    }

    public function test_the_csv_rows_hold_what_the_list_leaves_out(): void
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create([
            'company' => $company,
            'venue' => $venue,
            'contractStartOn' => new \DateTimeImmutable('2027-01-01'),
            'contractEndOn' => new \DateTimeImmutable('2027-12-31'),
        ]);

        $rows = iterator_to_array($this->query()->csvRows(new DealFilter(stage: 'lost'), DealSort::DEFAULT, SortDirection::Asc), false);

        self::assertCount(1, $rows);
        self::assertSame('Schloss Seeblick', $rows[0]->venueName);
        self::assertSame(LostReason::Budget, $rows[0]->lostReason);
        self::assertSame('2027-12-31', $rows[0]->contractEndOn?->format('Y-m-d'));
    }

    public function test_the_form_offers_the_companys_own_venues(): void
    {
        $venues = [
            VenueFactory::new()->withoutLocation(null)->create(['name' => 'Zunfthaus']),
            VenueFactory::new()->withoutLocation(null)->create(['name' => 'Alpenhof']),
        ];
        $company = CompanyFactory::new()->withVenues(...$venues)->create(['name' => 'Seeblick Events']);
        CompanyFactory::new()->withVenues(VenueFactory::new()->withoutLocation(null)->create())->create();

        $form = $this->forms()->company($company->id());

        self::assertNotNull($form);
        self::assertSame('Seeblick Events', $form->name);
        self::assertSame(['Alpenhof', 'Zunfthaus'], array_map(static fn ($option): string => $option->label, $form->venues));
    }

    public function test_an_unknown_or_malformed_company_is_no_form(): void
    {
        self::assertNull($this->forms()->company(EntityId::generate()));
        self::assertNull($this->forms()->company('not-a-uuid'));
    }

    private function query(): DealListQuery
    {
        $query = self::getContainer()->get(DealListQuery::class);
        self::assertInstanceOf(DealListQuery::class, $query);

        return $query;
    }

    private function forms(): DealFormQuery
    {
        $query = self::getContainer()->get(DealFormQuery::class);
        self::assertInstanceOf(DealFormQuery::class, $query);

        return $query;
    }
}
