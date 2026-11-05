<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CRM's groups in the global search (CRM-6, D20): below the venues, folded, and with a link to
 * the record. A contact has no group since #55: its name or e-mail finds its Location.
 */
final class SearchProvidersTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_an_editor_finds_a_company_by_folded_name(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Zürcher Gastro AG']);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        self::assertSame([[
            'label' => 'Locations',
            'results' => [['type' => 'company', 'label' => 'Zürcher Gastro AG', 'detail' => 'Kunde', 'url' => '/admin/crm/locations/'.$company->id()]],
        ]], $this->searchGroups('zuercher'));
    }

    public function test_an_editor_finds_a_deal_by_folded_name(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Alpenhof AG']);
        $deal = DealFactory::createOne(['name' => 'Verlängerung 2027', 'company' => $company]);
        DealFactory::createOne(['name' => 'Premium 2027', 'company' => CompanyFactory::createOne(['name' => 'Seeblick'])]);

        self::assertSame([[
            'label' => 'Deals',
            'results' => [['type' => 'deal', 'label' => 'Verlängerung 2027', 'detail' => 'Alpenhof AG', 'url' => '/admin/crm/deals/'.$deal->id()]],
        ]], $this->searchGroups('verlaengerung'));
    }

    public function test_a_location_is_found_by_its_contact_s_name_or_e_mail(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Alpenhof AG']);
        ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Müller', 'company' => $company, 'email' => 'laura@example.ch']);
        ContactFactory::createOne(['firstName' => 'Pia', 'lastName' => 'Brunner', 'company' => CompanyFactory::createOne(['name' => 'Seeblick']), 'email' => 'post@brunner.example.ch']);
        $hit = [[
            'label' => 'Locations',
            'results' => [['type' => 'company', 'label' => 'Alpenhof AG', 'detail' => 'Kunde', 'url' => '/admin/crm/locations/'.$company->id()]],
        ]];

        self::assertSame($hit, $this->searchGroups('mueller'));
        self::assertSame($hit, $this->searchGroups('laura@example'));
    }

    public function test_the_groups_stand_below_the_venues(): void
    {
        $this->logInAs(AdminRole::Editor);
        VenueFactory::new()->withoutLocation(null)->create(['name' => 'Seeblick Saal']);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        DealFactory::createOne(['name' => 'Seeblick 2027', 'company' => $company]);

        self::assertSame(['Einträge', 'Locations', 'Deals'], array_column($this->searchGroups('seeblick'), 'label'));
    }

    public function test_each_group_stops_at_five(): void
    {
        $this->logInAs(AdminRole::Editor);
        for ($i = 1; $i <= 6; ++$i) {
            CompanyFactory::createOne(['name' => 'Seeblick '.$i]);
        }

        self::assertCount(5, $this->searchGroups('seeblick')[0]['results']);
    }

    public function test_no_match_leaves_no_group(): void
    {
        $this->logInAs(AdminRole::Editor);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        self::assertSame([], $this->searchGroups('zuercher'));
        self::assertSame([], $this->searchGroups('!!!'));
    }

    /**
     * @return list<array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}>
     */
    private function searchGroups(string $q): array
    {
        $this->client->request('GET', '/admin/api/search?'.http_build_query(['q' => $q]));
        self::assertResponseIsSuccessful();

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer['groups'];
    }
}
