<?php

declare(strict_types=1);

namespace App\Story;

use App\Admin\Repository\AdminUserRepository;
use App\Crm\CompanyStageSync;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Venues\Entity\Venue;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\Tier;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;

/**
 * Fictional CRM data for the dev back office (#27): 12 companies, 8 of them made from the first
 * demo venues (so the bootstrap button still has venues to work on), their contacts, timeline
 * entries and tasks of the dev admin, due today, overdue and later, and 14 deals over every stage
 * of the pipeline. Seeded, so every `make seed` on an empty CRM writes the same data; it skips when
 * a company exists, because `make seed` appends.
 */
final class CrmStory extends Story
{
    private const LINKED_VENUES = 8;
    private const MANUAL_COMPANIES = ['Hochzeitswerk GmbH', 'Seeland Events AG', 'Alpenblick Hotels', 'Gastro Kollektiv'];
    /** A stage, and for a won deal its contract's end in days from today: one ended, one renewal due within 60 days, one running. */
    private const DEALS = [
        ['new', null], ['contacted', null], ['offer', null], ['negotiation', null], ['won', -30], ['lost', null], ['new', null],
        ['offer', null], ['won', 45], ['negotiation', null], ['contacted', null], ['won', 240], ['lost', null], ['offer', null],
    ];

    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly VenueRepository $venues,
        private readonly AdminUserRepository $users,
        private readonly CompanyStageSync $stageSync,
    ) {
    }

    public function build(): void
    {
        if ($this->companies->count([]) > 0) {
            return;
        }
        $admin = $this->users->findOneByEmail(AdminStory::EMAIL)
            ?? throw new \LogicException('CrmStory needs the dev admin: load AdminStory first (AppStory does).');

        $faker = faker();
        $faker->seed(20261031);
        $stages = [CompanyStage::Customer, CompanyStage::Prospect, CompanyStage::Prospect, CompanyStage::FormerCustomer];
        $types = [ActivityType::NOTE, ActivityType::CALL, ActivityType::MEETING, ActivityType::EMAIL_LOGGED];
        $today = new \DateTimeImmutable('today');

        $companies = [];
        $venues = $this->venues->findBy([], ['number' => 'ASC'], self::LINKED_VENUES);
        foreach ($venues as $index => $venue) {
            $companies[] = CompanyFactory::new()
                ->inStage($stages[$index % \count($stages)])
                ->withVenues($venue)
                ->create([
                    'name' => $venue->name(),
                    'origin' => CompanyOrigin::Venue,
                    'owner' => 0 === $index % 3 ? null : $admin,
                ]);
        }
        foreach (self::MANUAL_COMPANIES as $index => $name) {
            $companies[] = CompanyFactory::new()->withDetails()->inStage($stages[($index + 1) % \count($stages)])->create(['name' => $name, 'owner' => $admin]);
        }

        foreach ($companies as $index => $company) {
            $contacts = [];
            foreach (range(1, 1 + $index % 2) as $_) {
                $contacts[] = ContactFactory::new()->withDetails()->create([
                    'company' => $company,
                    'kind' => ContactKind::VenueContact,
                    'email' => 0 === $index % 4 ? null : $faker->unique()->safeEmail(),
                ]);
            }

            foreach (range(0, $index % 4) as $entry) {
                $activity = ActivityFactory::new()->ofType($types[($index + $entry) % \count($types)])->onCompany($company);
                if (0 === $entry) {
                    $activity = $activity->onContact($contacts[0]);
                }
                $activity->create(['actor' => $admin]);
            }

            if (0 === $index % 2) {
                TaskFactory::createOne([
                    'assignee' => $admin,
                    'createdBy' => $admin,
                    'dueOn' => $today->modify(\sprintf('%+d days', ($index % 5) - 2)),
                    'company' => $company,
                ]);
            }
        }

        ContactFactory::new()->withDetails()->many(3)->create(['kind' => ContactKind::Other]);
        TaskFactory::new()->done($today->modify('-1 day')->setTime(16, 0))->create(['assignee' => $admin, 'createdBy' => $admin, 'dueOn' => $today->modify('-2 days')]);
        TaskFactory::createOne(['assignee' => $admin, 'createdBy' => $admin, 'dueOn' => $today, 'contact' => ContactFactory::createOne(['kind' => ContactKind::Other])]);

        $this->deals($companies, $venues, $today);
        $this->stageSync->syncAll();
    }

    /**
     * @param list<Company> $companies
     * @param list<Venue>   $venues    the first companies' venues, in the same order
     */
    private function deals(array $companies, array $venues, \DateTimeImmutable $today): void
    {
        $faker = faker();
        $admin = $this->users->findOneByEmail(AdminStory::EMAIL);

        foreach (self::DEALS as $index => [$stage, $contractEnd]) {
            $company = $index % \count($companies);
            $attributes = [
                'name' => $faker->randomElement(['Premium', 'Top Premium', 'Empfehlung', 'Basic']).' '.$today->format('Y'),
                'company' => $companies[$company],
                'venue' => $venues[$company] ?? null,
                'tier' => $faker->randomElement(Tier::cases()),
                'amountRappen' => $faker->numberBetween(1, 40) * 50000,
                'owner' => 0 === $index % 3 ? null : $admin,
                'expectedCloseOn' => $today->modify(\sprintf('%+d days', 7 * ($index + 1))),
            ];
            if (null !== $contractEnd) {
                $end = $today->modify(\sprintf('%+d days', $contractEnd));
                $attributes['contractEndOn'] = $end;
                $attributes['contractStartOn'] = $end->modify('-1 year +1 day');
            }

            DealFactory::new()->inStage($stage, $today->modify(\sprintf('-%d days', $index + 1))->setTime(10, 0))->create($attributes);
        }
    }
}
