<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\Entity\AdminUser;
use App\Admin\ValueObject\AdminRole;
use App\Crm\Exception\VenueNotLinked;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Deal erfassen" (CRM-7, N5): the company first in a GET form, then the deal form with its venues.
 */
final class DealCreateTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use FailsCommands;

    private const string NEW = '/admin/crm/deals/neu';

    public function test_the_first_step_asks_for_the_company_by_get(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::NEW);

        self::assertResponseIsSuccessful();
        self::assertSame('get', strtolower((string) $crawler->filter('form.form')->attr('method')));
        self::assertCount(1, $crawler->filter('input[name="company"]'));
        self::assertCount(0, $crawler->filter('input[name="deal[name]"]'));
    }

    public function test_a_company_that_does_not_exist_is_asked_for_again(): void
    {
        $this->logInAs(AdminRole::Editor);

        foreach (['', 'Seeblick', EntityId::generate()] as $company) {
            $crawler = $this->client->request('GET', self::NEW.'?company='.$company);

            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('Bitte eine Location aus der Liste wählen.', $crawler->filter('.form')->text());
        }
    }

    public function test_an_editor_creates_a_deal_and_lands_on_its_record(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        AdminUserFactory::new()->afterInstantiate(static fn (AdminUser $user) => $user->deactivate())->create(['displayName' => 'Ehemalig']);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events']);
        ContactFactory::createOne(['company' => $company]);

        $crawler = $this->client->request('GET', self::NEW.'?company='.$company->id());
        self::assertResponseIsSuccessful();
        self::assertSame(['', $owner->id()], $crawler->filter('#deal_owner option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['', $venue->id()], $crawler->filter('#deal_venue option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSelectorExists('.field__addon input[name="deal[amount]"]');
        self::assertStringContainsString('Seeblick Events', $crawler->filter('.form')->text());
        // A deal has no contacts of its own since #55: its page shows the Location's.
        self::assertCount(0, $crawler->filter('[name^="deal[contacts]"]'));

        $this->client->submitForm('Erfassen', [
            'deal[name]' => '  Premium 2027 ',
            'deal[venue]' => $venue->id(),
            'deal[tier]' => 'premium',
            'deal[amount]' => "1'250.50",
            'deal[contractStart]' => '2027-01-01',
            'deal[contractEnd]' => '2027-12-31',
            'deal[expectedClose]' => '2026-11-15',
            'deal[owner]' => $owner->id(),
        ]);

        self::assertResponseStatusCodeSame(303);
        $row = $this->connection->fetchAssociative('SELECT * FROM crm_deals');
        self::assertIsArray($row);
        self::assertResponseRedirects('/admin/crm/deals/'.$row['id']);
        self::assertSame(
            ['Premium 2027', $company->id(), $venue->id(), 'premium', 125050, '2027-01-01', '2027-12-31', '2026-11-15', $owner->id(), 'new'],
            [$row['name'], $row['company_id'], $row['venue_id'], $row['tier'], (int) $row['amount_rappen'], $row['contract_start_on'], $row['contract_end_on'], $row['expected_close_on'], $row['owner_id'], $row['stage_code']],
        );
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'deal' AND subject_id = ?", [$row['id']]));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Premium 2027');
    }

    public function test_a_name_alone_is_enough(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('GET', self::NEW.'?company='.$company->id());

        $this->client->submitForm('Erfassen', ['deal[name]' => 'Premium 2027']);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->connection->fetchOne('SELECT amount_rappen FROM crm_deals'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedInput(): iterable
    {
        yield 'no name' => ['deal[name]', '', 'Bitte einen Wert eingeben.'];
        yield 'three decimals' => ['deal[amount]', '12.345', 'Bitte einen Betrag in Franken'];
        yield 'above the limit' => ['deal[amount]', '100000000', 'Bitte einen Betrag in Franken'];
        yield 'no such day' => ['deal[expectedClose]', '2027-02-30', 'Bitte ein gültiges Datum eingeben.'];
    }

    #[DataProvider('refusedInput')]
    public function test_bad_input_is_a_422_that_keeps_the_rest(string $field, string $value, string $message): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('GET', self::NEW.'?company='.$company->id());
        $crawler = $this->client->submitForm('Erfassen', ['deal[name]' => 'Premium 2027', $field => $value]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString($message, $crawler->filter('.form')->text());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_deals'));
    }

    public function test_a_contract_ending_before_its_start_lands_on_the_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('GET', self::NEW.'?company='.$company->id());
        $crawler = $this->client->submitForm('Erfassen', [
            'deal[name]' => 'Premium 2027',
            'deal[contractStart]' => '2027-01-01',
            'deal[contractEnd]' => '2026-12-31',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Das Vertragsende liegt vor dem Vertragsbeginn.', $crawler->filter('.form')->text());
    }

    public function test_a_foreign_venue_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $foreign = VenueFactory::new()->withoutLocation(null)->create();
        CompanyFactory::new()->withVenues($foreign)->create();
        $own = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($own)->create();

        $crawler = $this->client->request('GET', self::NEW.'?company='.$company->id());
        $form = $crawler->selectButton('Erfassen')->form();
        $values = $form->getPhpValues();
        \assert(\is_array($values['deal']));
        $values['deal']['name'] = 'Premium 2027';
        $values['deal']['venue'] = $foreign->id();
        $this->client->request('POST', self::NEW.'?company='.$company->id(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_deals'));
    }

    public function test_a_venue_unlinked_while_the_form_was_open_lands_on_the_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();

        $this->commandsFailWith(VenueNotLinked::toCompany($venue->id(), $company->id()));
        $this->client->request('GET', self::NEW.'?company='.$company->id());
        $crawler = $this->client->submitForm('Erfassen', ['deal[name]' => 'Premium 2027', 'deal[venue]' => $venue->id()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Dieser Eintrag gehört nicht zu dieser Location.', $crawler->filter('.form')->text());
    }

    public function test_an_unknown_failure_is_not_swallowed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->commandsFailWith(new \RuntimeException('Database gone.'));
        $this->client->request('GET', self::NEW.'?company='.$company->id());
        $this->client->submitForm('Erfassen', ['deal[name]' => 'Premium 2027']);

        self::assertResponseStatusCodeSame(500);
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('POST', self::NEW.'?company='.$company->id(), ['deal' => ['name' => 'Premium 2027']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM crm_deals'));
    }
}
