<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The company list (CRM-6, D20): columns, search, filters, sort, paging, and an invalid filter as the
 * unfiltered list at 422.
 */
final class CompanyListTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/crm/firmen');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_editor_sees_every_company_with_its_columns(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $seeblick = CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues($venue)->create(['name' => 'Seeblick Events GmbH', 'owner' => $owner]);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        $crawler = $this->client->request('GET', '/admin/crm/firmen');

        self::assertResponseIsSuccessful();
        self::assertSame(['Alpenhof AG', 'Seeblick Events GmbH'], $this->names($crawler));
        self::assertSelectorTextContains('h1', 'Firmen 2');
        $row = $crawler->filter('#company-list tbody tr')->eq(1);
        self::assertSame('/admin/crm/firmen/'.$seeblick->id(), $row->filter('.crm-list__link')->attr('href'));
        self::assertSame('SE', $row->filter('.avatar')->text());
        self::assertStringContainsString('Kunde', $row->text());
        self::assertStringContainsString('Andrea Keller', $row->text());
        self::assertSame('1', $row->filter('td')->eq(3)->text());
        self::assertCount(1, $crawler->filter('a[href="/admin/crm/firmen/neu"]'));
        self::assertSame('Firmen', $crawler->filter('.sidebar__item[aria-current="page"]')->text());
    }

    public function test_it_searches_by_folded_name(): void
    {
        $this->logInAs(AdminRole::Editor);
        CompanyFactory::createOne(['name' => 'Zürcher Gastro AG']);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        $crawler = $this->client->request('GET', '/admin/crm/firmen?q=zuercher');

        self::assertSame(['Zürcher Gastro AG'], $this->names($crawler));
        self::assertSelectorTextContains('.filter-chip', 'Suche: zuercher');
    }

    public function test_it_filters_by_stage_owner_and_venues(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues($venue)->create(['name' => 'Kunde mit Location', 'owner' => $owner]);
        CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Kunde ohne Location']);
        CompanyFactory::createOne(['name' => 'Interessent']);

        self::assertSame(['Kunde mit Location', 'Kunde ohne Location'], $this->names($this->client->request('GET', '/admin/crm/firmen?stage=customer')));
        self::assertSame(['Kunde mit Location'], $this->names($this->client->request('GET', '/admin/crm/firmen?owner='.$owner->id())));
        self::assertSame(['Interessent', 'Kunde ohne Location'], $this->names($this->client->request('GET', '/admin/crm/firmen?owner=none')));
        self::assertSame(['Kunde mit Location'], $this->names($this->client->request('GET', '/admin/crm/firmen?venues=with')));
        $crawler = $this->client->request('GET', '/admin/crm/firmen?venues=without&stage=customer');
        self::assertSame(['Kunde ohne Location'], $this->names($crawler));
        self::assertSame(['Phase: Kunde', 'Ohne Location'], $crawler->filter('.filter-chip')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function test_an_invalid_filter_shows_the_unfiltered_list_at_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        CompanyFactory::createOne(['name' => 'Alpenhof AG']);

        $crawler = $this->client->request('GET', '/admin/crm/firmen?stage=bogus&owner=nobody');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Alpenhof AG'], $this->names($crawler));
        self::assertSelectorTextContains('.form-error[role="alert"]', 'ungültig');
    }

    public function test_it_sorts_by_a_column_and_ignores_an_unknown_one(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venues = VenueFactory::new()->withoutLocation(null)->many(2)->create();
        CompanyFactory::new()->withVenues(...$venues)->create(['name' => 'Beta']);
        CompanyFactory::createOne(['name' => 'Alpha']);
        CompanyFactory::new()->inStage(CompanyStage::FormerCustomer)->create(['name' => 'Gamma']);

        self::assertSame(['Gamma', 'Beta', 'Alpha'], $this->names($this->client->request('GET', '/admin/crm/firmen?sort=name&dir=desc')));
        self::assertSame(['Beta', 'Alpha', 'Gamma'], $this->names($this->client->request('GET', '/admin/crm/firmen?sort=venues&dir=desc')));
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->names($this->client->request('GET', '/admin/crm/firmen?sort=stage')));
        $crawler = $this->client->request('GET', '/admin/crm/firmen?sort=evil&dir=sideways');
        self::assertResponseIsSuccessful();
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $this->names($crawler));
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
    }

    public function test_it_pages_by_fifty(): void
    {
        $this->logInAs(AdminRole::Editor);
        for ($i = 1; $i <= 51; ++$i) {
            CompanyFactory::createOne(['name' => \sprintf('Firma %02d', $i)]);
        }

        self::assertCount(50, $this->names($this->client->request('GET', '/admin/crm/firmen')));
        self::assertSame(['Firma 51'], $this->names($this->client->request('GET', '/admin/crm/firmen?page=2')));
        self::assertSame(['Firma 51'], $this->names($this->client->request('GET', '/admin/crm/firmen?page=99')));
    }

    public function test_an_empty_list_shows_the_empty_state(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/firmen?q=nichts');

        self::assertSelectorTextContains('.empty', 'Keine Firmen');
    }

    public function test_the_list_stays_within_its_statements(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        CompanyFactory::new()->withVenues(VenueFactory::new()->withoutLocation(null)->create())->create(['owner' => $owner]);
        CompanyFactory::createMany(2);

        // The owners, which carry the sidebar's badge (`TaskBadge`), COUNT, the rows, the venues left for the bootstrap,
        // the access-request badge; with the user's refresh 6 in production (P12 ceiling 5, passed on purpose by #43: that
        // badge has no statement to ride on).
        self::assertSame(5, $this->queriesOf('/admin/crm/firmen?stage=prospect&sort=venues'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#company-list .crm-list__link')->each(static fn (Crawler $link): string => $link->text());
    }
}
