<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Contact;
use App\Crm\ValueObject\ContactKind;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact list (CRM-6, D20): columns, search by name or e-mail, filters, sort, an invalid filter
 * as the unfiltered list at 422.
 */
final class ContactListTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/crm/kontakte');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_editor_sees_every_contact_with_its_columns(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $laura = ContactFactory::new()->afterInstantiate(static fn (Contact $contact) => $contact->changePosition('Eventmanagerin'))->create(['firstName' => 'Laura', 'lastName' => 'Meier', 'kind' => ContactKind::VenueContact, 'company' => $company, 'email' => 'laura@example.ch', 'owner' => $owner]);
        ContactFactory::createOne(['firstName' => null, 'lastName' => 'Brunner', 'kind' => ContactKind::Other, 'email' => null]);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte');

        self::assertResponseIsSuccessful();
        self::assertSame(['Brunner', 'Laura Meier'], $this->names($crawler));
        $row = $crawler->filter('#contact-list tbody tr')->eq(1);
        self::assertSame('/admin/crm/kontakte/'.$laura->id(), $row->filter('.crm-list__link')->attr('href'));
        self::assertSame('LM', $row->filter('.avatar')->text());
        self::assertStringContainsString('Eventmanagerin', $row->text());
        self::assertStringContainsString('Location-Kontakt', $row->text());
        self::assertSame('/admin/crm/firmen/'.$company->id(), $row->filter('a[href^="/admin/crm/firmen/"]')->attr('href'));
        self::assertStringContainsString('laura@example.ch', $row->text());
        self::assertStringContainsString('Andrea Keller', $row->text());
        self::assertSame('—', $crawler->filter('#contact-list tbody tr')->eq(0)->filter('td')->eq(2)->text());
        self::assertSame('Kontakte', $crawler->filter('.sidebar__item[aria-current="page"]')->text());
    }

    public function test_it_searches_by_name_and_email(): void
    {
        $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['firstName' => 'Jürg', 'lastName' => 'Müller', 'email' => 'info@alpenhof.ch']);
        ContactFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Brunner', 'email' => 'anna@seeblick.ch']);

        self::assertSame(['Jürg Müller'], $this->names($this->client->request('GET', '/admin/crm/kontakte?q=mueller')));
        self::assertSame(['Jürg Müller'], $this->names($this->client->request('GET', '/admin/crm/kontakte?q=juerg')));
        self::assertSame(['Anna Brunner'], $this->names($this->client->request('GET', '/admin/crm/kontakte?q=Seeblick.CH')));
        self::assertSame([], $this->names($this->client->request('GET', '/admin/crm/kontakte?q=%25')));
    }

    public function test_it_filters_by_kind_and_owner(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['lastName' => 'Brautpaar', 'firstName' => null, 'kind' => ContactKind::Couple, 'owner' => $owner]);
        ContactFactory::createOne(['lastName' => 'Betreiber', 'firstName' => null, 'kind' => ContactKind::VenueContact]);

        self::assertSame(['Brautpaar'], $this->names($this->client->request('GET', '/admin/crm/kontakte?kind=couple')));
        self::assertSame(['Brautpaar'], $this->names($this->client->request('GET', '/admin/crm/kontakte?owner='.$owner->id())));
        $crawler = $this->client->request('GET', '/admin/crm/kontakte?owner=none');
        self::assertSame(['Betreiber'], $this->names($crawler));
        self::assertSame(['Zuständig: Ohne Zuständige'], $crawler->filter('.filter-chip')->each(static fn (Crawler $chip): string => $chip->text()));
    }

    public function test_an_invalid_filter_shows_the_unfiltered_list_at_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['lastName' => 'Brunner', 'firstName' => null]);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte?kind=boss');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Brunner'], $this->names($crawler));
        self::assertSelectorExists('.form-error[role="alert"]');
    }

    public function test_it_sorts_by_company_with_the_unassigned_last(): void
    {
        $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['lastName' => 'Ohne', 'firstName' => null]);
        ContactFactory::createOne(['lastName' => 'Zett', 'firstName' => null, 'company' => CompanyFactory::createOne(['name' => 'Alpha AG'])]);
        ContactFactory::createOne(['lastName' => 'Ah', 'firstName' => null, 'company' => CompanyFactory::createOne(['name' => 'Beta AG'])]);

        self::assertSame(['Zett', 'Ah', 'Ohne'], $this->names($this->client->request('GET', '/admin/crm/kontakte?sort=company')));
        self::assertSame(['Zett', 'Ohne', 'Ah'], $this->names($this->client->request('GET', '/admin/crm/kontakte?sort=name&dir=desc')));
    }

    public function test_the_list_runs_three_queries(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        ContactFactory::createMany(3, ['company' => CompanyFactory::createOne(), 'owner' => $owner]);

        // The owners, which carry the sidebar's badge (`TaskBadge`), COUNT, the rows; with the user's refresh 4 in production.
        self::assertSame(3, $this->queriesOf('/admin/crm/kontakte?kind=other&sort=company'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('#contact-list .crm-list__link')->each(static fn (Crawler $link): string => $link->text());
    }
}
