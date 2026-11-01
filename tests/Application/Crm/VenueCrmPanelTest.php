<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\Extension\RecordPanel;
use App\Admin\Extension\RecordPanelRegistry;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\VenueNotFound;
use App\Crm\Extension\VenueCrmPanel;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Venues\DetailVenues;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The CRM card on the venue editor (`### 27.`, D20): the company and its contacts, or "Firma anlegen",
 * and exactly one statement more on the page.
 */
final class VenueCrmPanelTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use CountsQueries;
    use Factories;
    use FailsCommands;

    public function test_a_venue_with_a_company_shows_it_and_its_contacts(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues($venue)->create(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier', 'company' => $company]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertResponseIsSuccessful();
        $panel = $crawler->filter('aside section[aria-labelledby="crm"]');
        self::assertSame('CRM', $panel->filter('h2')->text());
        self::assertSame('/admin/crm/firmen/'.$company->id(), $panel->filter('.crm-panel__open')->attr('href'));
        self::assertSame('Firma öffnen', $panel->filter('.crm-panel__open')->text());
        self::assertSame('Seeblick Events GmbH', $panel->filter('.crm-panel__name')->text());
        self::assertSame('SE', $panel->filter('.avatar')->text());
        self::assertSame('Kunde · seit '.$company->createdAt()->setTimezone(new \DateTimeZone('Europe/Zurich'))->format('Y'), $panel->filter('.crm-panel__stage')->text());
        self::assertSame('Kontakt', $panel->filter('dt')->text());
        self::assertSame('Laura Meier', $panel->filter('dd a')->text());
        self::assertSame('/admin/crm/kontakte/'.$contact->id(), $panel->filter('dd a')->attr('href'));
        self::assertCount(0, $panel->filter('form'));
    }

    public function test_the_panel_lists_the_open_tasks_of_the_company_its_contacts_and_its_deals(): void
    {
        self::mockTime('2026-10-31 10:00:00 UTC');
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $contact = ContactFactory::createOne(['company' => $company]);
        TaskFactory::createOne(['title' => 'Vertrag senden', 'company' => $company, 'dueOn' => new \DateTimeImmutable('2026-10-30')]);
        TaskFactory::createOne(['title' => 'Rückruf', 'contact' => $contact, 'dueOn' => new \DateTimeImmutable('2026-10-31')]);
        TaskFactory::createOne(['title' => 'Verlängerung', 'deal' => DealFactory::createOne(['company' => $company]), 'dueOn' => new \DateTimeImmutable('2026-11-02')]);
        TaskFactory::new()->done(new \DateTimeImmutable('2026-10-29 10:00:00 UTC'))->create(['title' => 'Erledigt', 'company' => $company]);
        TaskFactory::createOne(['title' => 'Fremd', 'company' => CompanyFactory::createOne()]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        $panel = $crawler->filter('aside section[aria-labelledby="crm"]');
        self::assertSame('Offene Aufgaben', $panel->filter('.crm-panel__heading')->text());
        self::assertSame(['Vertrag senden Überfällig', 'Rückruf Heute', 'Verlängerung Mo, 2.11.'], $panel->filter('.crm-panel__task')->each(static fn (Crawler $task): string => $task->text()));
        self::assertCount(0, $panel->filter('form'), 'Read-only here: the toggles are on the company page.');
    }

    public function test_the_panel_shows_the_open_deal_that_closes_soonest(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        DealFactory::new()->inStage('offer')->create(['name' => 'Später', 'company' => $company, 'venue' => $venue, 'expectedCloseOn' => new \DateTimeImmutable('2027-06-01')]);
        $soonest = DealFactory::new()->inStage('negotiation')->create(['name' => 'Verlängerung 2027', 'company' => $company, 'venue' => $venue, 'amountRappen' => 480000, 'expectedCloseOn' => new \DateTimeImmutable('2027-01-15')]);
        DealFactory::new()->inStage('won')->create(['name' => 'Gewonnen', 'company' => $company, 'venue' => $venue, 'expectedCloseOn' => new \DateTimeImmutable('2026-01-01')]);
        DealFactory::createOne(['name' => 'Ohne Location', 'company' => $company, 'expectedCloseOn' => new \DateTimeImmutable('2026-01-01')]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        $panel = $crawler->filter('aside section[aria-labelledby="crm"]');
        self::assertSame(['Kontakt', 'Offener Deal', 'Phase', 'Wert'], $panel->filter('dt')->each(static fn (Crawler $term): string => $term->text()));
        self::assertSame('Verlängerung 2027', $panel->filter('dd a')->text());
        self::assertSame('/admin/crm/deals/'.$soonest->id(), $panel->filter('dd a')->attr('href'));
        self::assertSame('Verhandlung', $panel->filter('dd .pill')->text());
        self::assertSame("CHF 4'800", $panel->filter('dd.crm-panel__amount')->text());
    }

    public function test_a_venue_without_an_open_deal_shows_no_deal_lines(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        DealFactory::new()->inStage('lost')->create(['company' => $company, 'venue' => $venue]);

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertSame(['Kontakt'], $crawler->filter('aside section[aria-labelledby="crm"] dt')->each(static fn (Crawler $term): string => $term->text()));
    }

    public function test_a_company_without_open_tasks_shows_no_heading(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertSelectorExists('.crm-panel__name');
        self::assertSelectorNotExists('.crm-panel__heading');
    }

    public function test_a_company_without_contacts_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        CompanyFactory::new()->withVenues($venue)->create();

        $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertSelectorTextSame('.crm-panel__facts dd', 'Noch kein Kontakt');
    }

    public function test_the_panel_sits_between_the_checklist_and_the_history(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        self::assertSame(
            ['venue-checklist', 'crm', 'venue-history'],
            $crawler->filter('aside section[aria-labelledby]')->each(static fn (Crawler $card): ?string => $card->attr('aria-labelledby')),
        );
    }

    public function test_a_venue_without_a_company_offers_to_create_one(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        $panel = $crawler->filter('aside section[aria-labelledby="crm"]');
        self::assertSame('Diese Location gehört noch zu keiner Firma.', $panel->filter('p')->text());
        self::assertSame('/admin/crm/locations/'.$venue->id().'/firma', $panel->filter('form')->attr('action'));
        self::assertSame('Firma anlegen', $panel->filter('form button')->text());
        self::assertCount(0, $panel->filter('.crm-panel__open'));
    }

    public function test_the_button_creates_the_company_and_returns_to_the_editor(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $crawler = $this->client->request('GET', '/admin/locations/'.$venue->number());

        $this->client->submit($crawler->filter('section[aria-labelledby="crm"] form')->form());

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#crm', 303);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM crm_companies WHERE origin = 'venue' AND name = ?", [$venue->name()]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Firma angelegt.');
        self::assertSame($venue->name(), $crawler->filter('.crm-panel__name')->text());
    }

    public function test_a_second_tab_finds_the_company_already_there(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('section[aria-labelledby="crm"] form')->form();
        $this->client->submit($form);
        $this->client->followRedirect();

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#crm', 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_companies'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [CreateCompanyForVenue::class]), 'A stale tab must not log a company that never existed.');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Diese Location hat bereits eine Firma.');
    }

    public function test_an_address_taken_at_the_last_moment_is_a_flash(): void
    {
        $this->commandsFailWith(ContactEmailTaken::create());
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('section[aria-labelledby="crm"] form')->form();

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#crm', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Firma nicht angelegt');
    }

    public function test_a_venue_deleted_at_the_last_moment_is_a_404(): void
    {
        $this->commandsFailWith(VenueNotFound::forId(EntityId::generate()));
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $form = $this->client->request('GET', '/admin/locations/'.$venue->number())->filter('section[aria-labelledby="crm"] form')->form();

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_post_without_its_token_creates_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();

        $this->client->request('POST', '/admin/crm/locations/'.$venue->id().'/firma');

        self::assertResponseRedirects('/admin/locations/'.$venue->number().'#crm', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_companies'));
    }

    public function test_an_unknown_venue_is_not_found(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/locations/'.EntityId::generate().'/firma');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_panel_adds_exactly_one_statement_to_the_editor(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = DetailVenues::withoutPlace();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        ContactFactory::createMany(3, ['company' => $company]);
        $url = '/admin/locations/'.$venue->number();

        // The registry keeps this object, so the panel can join between two requests of one client.
        /** @var \ArrayObject<int, RecordPanel> $panels */
        $panels = new \ArrayObject();
        $authorization = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $authorization);
        $query = self::getContainer()->get(VenueCompanyQuery::class);
        self::assertInstanceOf(VenueCompanyQuery::class, $query);
        self::getContainer()->set(RecordPanelRegistry::class, new RecordPanelRegistry($panels, $authorization));

        $this->queriesOf($url); // The first request fills what later ones find memoized.
        $without = $this->queriesOf($url);
        self::assertSelectorNotExists('.crm-panel__name');

        $panels->append(new VenueCrmPanel($query));
        $with = $this->queriesOf($url);
        self::assertSelectorExists('.crm-panel__name');

        self::assertSame($without + 1, $with);
    }
}
