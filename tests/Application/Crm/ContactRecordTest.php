<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactKind;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
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
            ['name' => 'company', 'label' => 'Firma', 'type' => 'combobox', 'value' => $company->id(), 'display' => 'Seeblick Events GmbH', 'lookupUrl' => '/admin/api/crm/companies'],
            $props['fields'][3],
        );
        self::assertStringContainsString('laura@example.ch', $crawler->filter('[data-island="record-properties"] .record-properties')->text());

        self::assertSame('/admin/crm/firmen/'.$company->id(), $crawler->filter('.crm-side .crm-side__link')->attr('href'));
        self::assertSame('Kunde', $crawler->filter('.crm-side .pill')->text());
        self::assertSelectorNotExists('#record-actions');
    }

    public function test_a_contact_without_company_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner']);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/'.$contact->id());

        self::assertSame('Brunner', $crawler->filter('h1#record-title')->text());
        self::assertSelectorTextContains('.crm-side', 'Keiner Firma zugeordnet.');
    }

    public function test_an_unknown_contact_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/kontakte/'.EntityId::generate());

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_page_runs_two_queries(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'owner' => $owner]);

        // The contact with its company and owner, the owners; with the user's refresh 3 in production (P12 ceiling 4).
        self::assertSame(2, $this->queriesOf('/admin/crm/kontakte/'.$contact->id()));
    }
}
