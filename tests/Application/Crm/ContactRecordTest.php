<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\EmailTemplateFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * A contact record (CRM-2, CRM-3): the properties card with its island and fallback, and the Firma card.
 */
final class ContactRecordTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    public function test_the_page_shows_the_contact_and_its_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::new()->afterInstantiate(static fn (Contact $contact) => $contact->changePosition('Eventmanagerin'))->create([
            'firstName' => 'Laura',
            'lastName' => 'Meier',
            'kind' => ContactKind::VenueContact,
            'company' => $company,
            'email' => 'laura@example.ch',
        ]);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertResponseIsSuccessful();
        self::assertSame('Laura Meier', $crawler->filter('h1#record-title')->text());
        self::assertSame('LM', $crawler->filter('.crm-record__identity .avatar')->text());
        self::assertSame('Eventmanagerin', $crawler->filter('.crm-record__identity [data-crm-follow="position"]')->text());
        self::assertSame('Location-Kontakt', $crawler->filter('.crm-record__identity .pill')->text());
        self::assertSame(['CRM', 'Kontakte', 'Laura Meier'], $crawler->filter('.breadcrumbs__item')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('Meier', $crawler->filter('.breadcrumbs__item [data-crm-follow="last_name"]')->text());
        self::assertSame('Kontakte', $crawler->filter('.sidebar__item[aria-current="page"]')->text());
        // The Firma card holds initials and a stage that an inline save cannot rebuild, so it reloads the page.
        self::assertSelectorExists('section[aria-labelledby="crm-company-title"][data-crm-refresh="company"]');

        $props = json_decode((string) $crawler->filter('[data-island="record-properties"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('/admin/api/crm/contacts/'.$contact->id(), $props['url']);
        self::assertSame(['first_name', 'last_name', 'kind', 'company', 'position', 'email', 'phone', 'owner'], array_column($props['fields'], 'name'));
        self::assertSame(
            ['name' => 'company', 'label' => 'Location', 'type' => 'combobox', 'value' => $company->id(), 'display' => 'Seeblick Events GmbH', 'lookupUrl' => '/admin/api/crm/companies'],
            $props['fields'][3],
        );
        self::assertStringContainsString('laura@example.ch', $crawler->filter('[data-island="record-properties"] .record-properties')->text());

        self::assertSame('/admin/crm/locations/'.$company->id(), $crawler->filter('.crm-side .crm-side__link')->attr('href'));
        self::assertSame('Kunde', $crawler->filter('.crm-side .pill')->text());
        self::assertSelectorNotExists('#record-actions');
    }

    public function test_a_contact_without_company_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner']);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertSame('Brunner', $crawler->filter('h1#record-title')->text());
        self::assertSelectorTextContains('.crm-side', 'Keiner Location zugeordnet.');
    }

    public function test_an_unknown_contact_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/kontakte/'.EntityId::generate());

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_page_mounts_the_timeline_with_its_first_page(): void
    {
        ['user' => $author] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        ActivityFactory::new()->ofType(ActivityType::CALL)->onContact($contact)->create([
            'actor' => $author,
            'body' => '<b>Rückruf</b>',
            'occurredAt' => new \DateTimeImmutable('2026-10-29T14:02:00+01:00'),
        ]);
        ActivityFactory::new()->onCompany($company)->create(['body' => 'Nur auf der Firma']);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('.crm-record__center [data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('contact', $props['kind']);
        self::assertSame($contact->id(), $props['recordId']);
        self::assertSame('/admin/api/crm/contacts/'.$contact->id().'/timeline', $props['urls']['timeline']);
        self::assertSame(['<b>Rückruf</b>'], array_column($props['page']['entries'], 'body'));

        $fallback = $crawler->filter('[data-island="timeline"] .timeline-item');
        self::assertCount(1, $fallback);
        self::assertSame('Anruf von Andrea Keller', $fallback->filter('.timeline-item__title')->text());
        self::assertSame('<b>Rückruf</b>', $fallback->filter('.muted')->text());
        self::assertCount(0, $fallback->filter('b'));
        self::assertStringNotContainsString('<b>Rückruf', (string) $this->client->getResponse()->getContent());

        self::assertCount(4, $crawler->filter('.crm-record__card [data-composer-tab]'));
    }

    public function test_the_deals_card_shows_the_deals_the_contact_is_on(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['name' => 'Premium 2027', 'company' => $company]);
        DealFactory::createOne(['name' => 'Anderer Deal', 'company' => $company]);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('crm_deal_contacts', ['deal_id' => $deal->id(), 'contact_id' => $contact->id()]);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertSame('Deals (1)', $crawler->filter('#deals h2')->text());
        self::assertSame('Premium 2027', $crawler->filter('#deals .crm-side__link')->text());
        self::assertCount(0, $crawler->filter('#deals .card__head a'));
    }

    public function test_the_page_stays_within_its_statements(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'owner' => $owner, 'email' => 'mia@example.ch']);
        TaskFactory::createOne(['contact' => $contact]);

        // The contact with its company and owner, the owners, which carry the sidebar's badge (`TaskBadge`), the
        // timeline's activities, the composer's mail templates, the Aufgaben card, the Deals card, the access-request
        // badge; with the user's refresh 8 in production (N14's ceiling 9). The address is the worst case: without one the
        // templates are not read.
        self::assertSame(7, $this->queriesOf('/admin/crm/kontakte/'.$contact->id()));
    }

    public function test_a_contact_without_an_address_reads_no_templates(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'owner' => $owner, 'email' => null]);
        TaskFactory::createOne(['contact' => $contact]);
        EmailTemplateFactory::createOne();
        $url = '/admin/crm/kontakte/'.$contact->id();

        // The pin above without the composer's mail templates.
        self::assertSame(6, $this->queriesOf($url));

        $crawler = $this->client->request('GET', $url);
        $props = json_decode((string) $crawler->filter('.crm-record__center [data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame([], $props['email']['recipients']);
        self::assertSame([], $props['email']['templates']);
    }
}
