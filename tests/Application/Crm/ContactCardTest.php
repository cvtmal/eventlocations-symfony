<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\ChangeContact;
use App\Crm\Command\CreateContact;
use App\Crm\Command\RemoveContact;
use App\Crm\Entity\Contact;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Kontakte card of a Location (CRM-2, #55): a contact is a row with an add and an edit dialog, no page of its own.
 * Every post answers a flash and a 303 back to the card; the audit row hangs on the Location.
 */
final class ContactCardTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_the_card_lists_the_contacts_with_their_dialogs_and_no_page_link(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $laura = ContactFactory::new()->afterInstantiate(static function (Contact $contact): void {
            $contact->changePosition('Eventmanagerin');
            $contact->changePhone('+41 44 123 45 67');
        })->create(['company' => $company, 'firstName' => 'Laura', 'lastName' => 'Meier', 'email' => 'laura@seeblick.example']);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $add = $crawler->filter('#kontakte .card__head button[command="show-modal"]');
        self::assertSame(['contact-new', 'Kontakt bei dieser Location erfassen'], [$add->attr('commandfor'), $add->attr('aria-label')]);
        self::assertSame('Kontakt erfassen', $crawler->filter('#contact-new-title')->text());
        self::assertSame(['Laura Meier', 'Eventmanagerin', 'laura@seeblick.example', '+41 44 123 45 67'], $crawler->filter('#kontakte .crm-side__text > *')->each(static fn (Crawler $line): string => $line->text()));
        self::assertSame('mailto:laura@seeblick.example', $crawler->filter('#kontakte a[href^="mailto:"]')->attr('href'));
        self::assertSame('tel:+41441234567', $crawler->filter('#kontakte a[href^="tel:"]')->attr('href'));
        self::assertCount(0, $crawler->filter('a[href*="/admin/crm/kontakte"]'));

        $edit = $crawler->filter('#kontakte .crm-side__item button[command="show-modal"]');
        self::assertSame(['contact-'.$laura->id(), 'Laura Meier bearbeiten', 'Bearbeiten'], [$edit->attr('commandfor'), $edit->attr('aria-label'), $edit->text()]);
        $dialog = $crawler->filter('#contact-'.$laura->id());
        self::assertSame('Kontakt bearbeiten', $dialog->filter('.modal__title')->text());
        self::assertSame('Meier', $dialog->filter('input[name="contact_'.$laura->id().'[lastName]"]')->attr('value'));
        self::assertSame('email', $dialog->filter('input[name="contact_'.$laura->id().'[email]"]')->attr('type'));
        self::assertNotNull($dialog->filter('input[name="contact_'.$laura->id().'[lastName]"]')->attr('required'));
        self::assertSame('/admin/crm/locations/'.$company->id().'/kontakte/'.$laura->id().'/entfernen', $dialog->filter('form.crm-contact__remove')->attr('action'));
    }

    public function test_a_contact_is_added_through_the_dialog(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#contact-new-form')->form([
            'contact_new[firstName]' => ' Laura ',
            'contact_new[lastName]' => 'Meier',
            'contact_new[position]' => 'Eventmanagerin',
            'contact_new[email]' => 'Laura.Meier@Example.ch',
            'contact_new[phone]' => '',
        ]));

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        $row = $this->connection->fetchAssociative('SELECT id, company_id, first_name, last_name, position, email, phone FROM crm_contacts');
        self::assertIsArray($row);
        self::assertSame([$company->id(), 'Laura', 'Meier', 'Eventmanagerin', 'laura.meier@example.ch', null], [$row['company_id'], $row['first_name'], $row['last_name'], $row['position'], $row['email'], $row['phone']]);
        self::assertEquals([CreateContact::class => ['contact' => $row['id'], 'fields' => ['first_name', 'last_name', 'email', 'position']]], $this->audit($company->id()));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Kontakt «Laura Meier» erfasst.');
        self::assertSame(['Laura Meier'], $crawler->filter('#kontakte .crm-side__name')->each(static fn (Crawler $name): string => $name->text()));
    }

    public function test_a_bad_dialog_writes_nothing_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $form = $this->client->request('GET', '/admin/crm/locations/'.$company->id())->filter('#contact-new-form')->form();

        $this->client->submit($form, ['contact_new[lastName]' => '   ']);
        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Kontakt nicht gespeichert: Nachname fehlt oder eine Angabe ist ungültig.');

        $this->client->submit($form, ['contact_new[lastName]' => 'Meier', 'contact_new[email]' => 'keine-adresse']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Kontakt nicht gespeichert');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
        self::assertSame([], $this->audit($company->id()));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_add_writes_nothing(array $token, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/kontakte', ['contact_new' => ['lastName' => 'Meier'] + $token], server: $server);

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
    }

    public function test_an_e_mail_is_unique_per_location_only(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $company, 'email' => 'laura@example.ch']);
        ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'email' => 'mia@example.ch']);
        $form = $this->client->request('GET', '/admin/crm/locations/'.$company->id())->filter('#contact-new-form')->form();

        $this->client->submit($form, ['contact_new[lastName]' => 'Meier', 'contact_new[email]' => 'LAURA@example.ch']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Diese E-Mail-Adresse steht schon bei einem Kontakt dieser Location.');
        self::assertSame(1, $this->contactsOf($company->id()));

        $this->client->submit($form, ['contact_new[lastName]' => 'Meier', 'contact_new[email]' => 'mia@example.ch']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Kontakt «Meier» erfasst.');
        self::assertSame(2, $this->contactsOf($company->id()));
    }

    public function test_a_contact_is_edited_through_its_dialog(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $laura = ContactFactory::createOne(['company' => $company, 'firstName' => 'Laura', 'lastName' => 'Meier', 'email' => 'laura@example.ch']);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $name = 'contact_'.$laura->id();
        $this->client->submit($crawler->filter('#contact-'.$laura->id().'-form')->form([$name.'[lastName]' => 'Keller', $name.'[phone]' => '044 123 45 67']));

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        self::assertSame(['Laura', 'Keller', 'laura@example.ch', '044 123 45 67'], array_values((array) $this->connection->fetchAssociative('SELECT first_name, last_name, email, phone FROM crm_contacts')));
        self::assertEquals([ChangeContact::class => ['contact' => $laura->id(), 'fields' => ['last_name', 'phone']]], $this->audit($company->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Kontakt «Laura Keller» gespeichert.');
    }

    public function test_an_unchanged_save_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $laura = ContactFactory::createOne(['company' => $company, 'firstName' => 'Laura', 'lastName' => 'Meier', 'email' => 'laura@example.ch']);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#contact-'.$laura->id().'-form')->form(['contact_'.$laura->id().'[email]' => ' LAURA@example.ch ']));

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        self::assertSame([], $this->audit($company->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Nichts geändert.');
    }

    public function test_a_contact_of_another_location_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $other = ContactFactory::createOne(['company' => CompanyFactory::createOne(), 'lastName' => 'Meier']);

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/kontakte/'.$other->id(), ['contact_'.$other->id() => ['lastName' => 'Keller']]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/admin/crm/locations/'.EntityId::generate().'/kontakte', ['contact_new' => ['lastName' => 'Keller']]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame('Meier', $this->connection->fetchOne('SELECT last_name FROM crm_contacts'));
    }

    public function test_a_contact_is_removed_and_only_its_row_goes(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $laura = ContactFactory::createOne(['company' => $company, 'firstName' => 'Laura', 'lastName' => 'Meier']);
        ActivityFactory::new()->onCompany($company)->create();
        TaskFactory::createOne(['company' => $company]);
        DealFactory::createOne(['company' => $company]);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#contact-'.$laura->id().' form.crm-contact__remove')->form());

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
        self::assertSame([1, 1, 1], [
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_activities'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_tasks'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_deals'),
        ]);
        self::assertSame([RemoveContact::class => ['contact' => $laura->id()]], $this->audit($company->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Kontakt «Laura Meier» entfernt.');
        self::assertSelectorTextContains('#kontakte', 'Noch keine Kontakte.');
    }

    public function test_a_remove_without_its_token_or_of_another_location_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $laura = ContactFactory::createOne(['company' => $company]);
        $other = ContactFactory::createOne(['company' => CompanyFactory::createOne()]);

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/kontakte/'.$laura->id().'/entfernen');
        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('.toast--error');

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/kontakte/'.$other->id().'/entfernen', ['_token' => 'csrf-token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#kontakte', 303);

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
        self::assertSame([], $this->audit($company->id()));
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $company = CompanyFactory::createOne();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/kontakte', ['contact_new' => ['lastName' => 'Meier']]);

        self::assertResponseRedirects('/admin/login');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
    }

    private function contactsOf(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts WHERE company_id = ?', [$companyId]);
    }

    /**
     * @return array<string, mixed> command => changes, of the Location's audit rows (jsonb orders the keys its own way,
     *                              so compare with `assertEquals`)
     */
    private function audit(string $companyId): array
    {
        $rows = $this->connection->fetchAllKeyValue("SELECT command, changes FROM admin_audit_log WHERE subject_type = 'company' AND subject_id = ? ORDER BY id", [$companyId]);

        return array_map(static fn (mixed $changes): mixed => json_decode((string) $changes, true, flags: \JSON_THROW_ON_ERROR), $rows);
    }
}
