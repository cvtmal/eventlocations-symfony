<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Kontakt erfassen" (CRM-2): a 303 to the new record, the company preset by "+ Hinzufügen", a taken
 * e-mail address as a 422 on its field.
 */
final class ContactCreateTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use ForgedPosts;

    public function test_a_contact_is_created_at_the_preset_company(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/neu?company='.$company->id());
        self::assertResponseIsSuccessful();
        $mount = $crawler->filter('[data-island="combobox"]');
        self::assertStringContainsString('Seeblick Events GmbH', (string) $mount->attr('data-props'));
        self::assertSame($company->id(), $crawler->filter('input[name="contact[company]"]')->attr('value'));

        $this->client->submitForm('Erfassen', [
            'contact[firstName]' => 'Laura',
            'contact[lastName]' => 'Meier',
            'contact[kind]' => 'venue_contact',
            'contact[position]' => 'Eventmanagerin',
            'contact[email]' => 'Laura.Meier@Example.CH',
        ]);

        self::assertResponseStatusCodeSame(303);
        $row = $this->connection->fetchAssociative('SELECT id, first_name, last_name, kind, company_id, email, position FROM crm_contacts');
        self::assertIsArray($row);
        self::assertResponseRedirects('/admin/crm/kontakte/'.$row['id']);
        self::assertSame(['Laura', 'Meier', 'venue_contact', $company->id(), 'laura.meier@example.ch', 'Eventmanagerin'], [$row['first_name'], $row['last_name'], $row['kind'], $row['company_id'], $row['email'], $row['position']]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Laura Meier');
        self::assertSelectorTextContains('.toast', 'Kontakt «Laura Meier» erfasst.');
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_creates_nothing(array $token, array $server): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/kontakte/neu', ['contact' => ['lastName' => 'Meier', 'kind' => 'venue_contact'] + $token], server: $server);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('CSRF', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
    }

    public function test_an_unknown_preset_company_is_ignored(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/crm/kontakte/neu?company='.EntityId::generate());

        self::assertResponseIsSuccessful();
        self::assertSame('', (string) $crawler->filter('input[name="contact[company]"]')->attr('value'));
    }

    public function test_a_taken_email_is_a_422_on_its_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['email' => 'laura@example.ch']);
        $this->client->request('GET', '/admin/crm/kontakte/neu');

        $crawler = $this->client->submitForm('Erfassen', ['contact[lastName]' => 'Meier', 'contact[email]' => 'LAURA@example.ch']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Ein anderer Kontakt hat bereits diese E-Mail-Adresse.', $crawler->filter('#contact_email_error')->text());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
    }

    public function test_an_unknown_company_is_a_422_that_keeps_the_input(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/crm/kontakte/neu');

        $crawler = $this->client->submitForm('Erfassen', ['contact[lastName]' => 'Meier', 'contact[company]' => EntityId::generate()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Eintrag aus der Liste wählen.', $crawler->filter('#contact_company_error')->text());
        self::assertSame('Meier', $crawler->filter('#contact_lastName')->attr('value'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
    }

    public function test_a_missing_last_name_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/crm/kontakte/neu');

        $crawler = $this->client->submitForm('Erfassen', ['contact[firstName]' => 'Laura', 'contact[company]' => 'not-an-id']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#contact_lastName')->attr('aria-invalid'));
        self::assertSame('true', $crawler->filter('#contact_company')->attr('aria-invalid'));
    }
}
