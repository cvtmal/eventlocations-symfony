<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CRM comboboxes' sources in `ComboboxType`'s shape: companies by name, venues by name or number
 * with the company a venue already belongs to.
 */
final class LookupApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_companies_are_found_by_folded_name(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Zürcher Gastro AG']);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        self::assertSame(['options' => [['value' => $company->id(), 'label' => 'Zürcher Gastro AG', 'detail' => 'Kunde']]], $this->get('/admin/api/crm/companies?q=zuercher'));
        self::assertSame(['options' => []], $this->get('/admin/api/crm/companies?q=z'));
        self::assertSame(['options' => []], $this->get('/admin/api/crm/companies?q[]=x'));
        self::assertSame(['options' => []], $this->get('/admin/api/crm/companies?q=%00ab'));
    }

    public function test_venues_are_found_by_name_or_number_with_their_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $free = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Seeblick Lodge']);
        CompanyFactory::new()->withVenue($venue)->create(['name' => 'Seeblick Events GmbH']);

        self::assertSame(['options' => [
            ['value' => $venue->id(), 'label' => 'Schloss Seeblick', 'detail' => 'Nr. '.$venue->number().' · bei Seeblick Events GmbH'],
            ['value' => $free->id(), 'label' => 'Seeblick Lodge', 'detail' => 'Nr. '.$free->number()],
        ]], $this->get('/admin/api/crm/venues?q=seeblick'));
        self::assertSame([$free->id()], array_column($this->get('/admin/api/crm/venues?q='.$free->number())['options'], 'value'));
        self::assertSame(['options' => []], $this->get('/admin/api/crm/venues?q=99999999999'));
    }

    public function test_anonymous_gets_a_json_401(): void
    {
        $this->client->request('GET', '/admin/api/crm/companies?q=ab');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array{options: list<array<string, string>>}
     */
    private function get(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        /** @var array{options: list<array<string, string>>} $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('options', $answer);

        return $answer;
    }
}
