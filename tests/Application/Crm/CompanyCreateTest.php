<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Exception\InvalidPropertyValue;
use App\Factory\AdminUserFactory;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Firma erfassen" (CRM-1): a 303 to the new record, a 422 that keeps the input, any staff role.
 */
final class CompanyCreateTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use FailsCommands;
    use ForgedPosts;

    public function test_an_editor_creates_a_company_and_lands_on_its_record(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        AdminUserFactory::new()->afterInstantiate(static fn (AdminUser $user) => $user->deactivate())->create(['displayName' => 'Ehemalig']);

        $crawler = $this->client->request('GET', '/admin/crm/locations/neu');
        self::assertResponseIsSuccessful();
        self::assertSame(['', $owner->id()], $crawler->filter('#company_owner option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 'A deactivated account is not offered.');

        $this->client->submitForm('Erfassen', [
            'company[name]' => '  Seeblick Events GmbH ',
            'company[website]' => 'https://seeblick.example',
            'company[phone]' => '+41 44 000 00 00',
            'company[email]' => 'Info@Seeblick.example',
            'company[owner]' => $owner->id(),
        ]);

        self::assertResponseStatusCodeSame(303);
        $row = $this->connection->fetchAssociative('SELECT id, name, website, email, owner_id, origin, stage FROM crm_companies');
        self::assertIsArray($row);
        self::assertResponseRedirects('/admin/crm/locations/'.$row['id']);
        self::assertSame(['Seeblick Events GmbH', 'https://seeblick.example', 'info@seeblick.example', $owner->id(), 'manual', 'prospect'], [$row['name'], $row['website'], $row['email'], $row['owner_id'], $row['origin'], $row['stage']]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Seeblick Events GmbH');
        self::assertSelectorTextContains('.toast', 'Location «Seeblick Events GmbH» erfasst.');
    }

    public function test_invalid_input_is_a_422_on_the_fields(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/crm/locations/neu');

        $crawler = $this->client->submitForm('Erfassen', [
            'company[name]' => '   ',
            'company[website]' => 'seeblick.example',
            'company[phone]' => 'ruf an',
            'company[email]' => 'kein mail',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
        foreach (['name', 'website', 'phone', 'email'] as $field) {
            self::assertSame('true', $crawler->filter('#company_'.$field)->attr('aria-invalid'), $field);
        }
        self::assertSame('seeblick.example', $crawler->filter('#company_website')->attr('value'));
    }

    public function test_an_owner_deactivated_after_the_form_checked_it_is_a_422_on_the_field(): void
    {
        $this->commandsFailWith(InvalidPropertyValue::unknown('owner'));
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/crm/locations/neu');

        $crawler = $this->client->submitForm('Erfassen', ['company[name]' => 'Seeblick Events GmbH']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Bitte einen Eintrag aus der Liste wählen.', $crawler->filter('#company_owner_error')->text());
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_creates_nothing(array $token, array $server): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/locations/neu', ['company' => ['name' => 'Seeblick Events'] + $token], server: $server);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Das Formular konnte nicht geprüft werden.', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
    }

    public function test_a_nul_character_is_refused_once(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('GET', '/admin/crm/locations/neu');

        $crawler = $this->client->submitForm('Erfassen', ['company[name]' => "See\0blick"]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Der Text enthält ungültige Zeichen.', $crawler->filter('#company_name_error')->text());
    }

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/crm/locations/neu');

        self::assertResponseRedirects('/admin/login');
    }
}
