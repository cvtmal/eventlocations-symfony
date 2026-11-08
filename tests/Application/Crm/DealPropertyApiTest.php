<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\AdminUserFactory;
use App\Factory\CompanyFactory;
use App\Factory\DealFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\KnownTiers;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The deal's inline edit (D17-a, N4): the shape of the company endpoint, with the stored form as the version and a
 * `display` beside it.
 */
final class DealPropertyApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_save_answers_the_value_and_writes_one_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['name' => 'Premium 2027']);

        $this->patch($deal->id(), ['field' => 'name', 'value' => '  Premium 2028 ', 'expected' => 'Premium 2027']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'Premium 2028', 'display' => null, 'draft' => null], $this->answer());
        self::assertSame(1, $this->auditRows($deal->id()));
    }

    public function test_an_amount_answers_rappen_and_its_display(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->patch($deal->id(), ['field' => 'amount', 'value' => "1'250.50", 'expected' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => '125050', 'display' => "CHF 1'250.50", 'draft' => "1'250.50"], $this->answer());
        self::assertSame(125050, (int) $this->connection->fetchOne('SELECT amount_rappen FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_a_date_answers_the_day_and_its_display(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->patch($deal->id(), ['field' => 'expected_close', 'value' => '2027-03-01', 'expected' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => '2027-03-01', 'display' => '01.03.2027', 'draft' => null], $this->answer());
    }

    public function test_emptying_an_optional_field_stores_null(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['amountRappen' => 100]);

        $this->patch($deal->id(), ['field' => 'amount', 'value' => ' ', 'expected' => '100']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => null, 'display' => null, 'draft' => null], $this->answer());
    }

    public function test_an_owner_tier_and_venue_take_their_ids_and_codes(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::createOne();
        $company = CompanyFactory::new()->withVenue($venue)->create();
        $owner = AdminUserFactory::createOne();
        $deal = DealFactory::createOne(['company' => $company]);

        $this->patch($deal->id(), ['field' => 'owner', 'value' => $owner->id(), 'expected' => null]);
        self::assertSame(['value' => $owner->id(), 'display' => null, 'draft' => null], $this->answer());

        $this->patch($deal->id(), ['field' => 'tier', 'value' => KnownTiers::PRO, 'expected' => null]);
        self::assertSame(['value' => 'pro', 'display' => null, 'draft' => null], $this->answer());
        self::assertSame('pro', $this->connection->fetchOne('SELECT t.code FROM crm_deals d JOIN tiers t ON t.id = d.tier_id WHERE d.id = ?', [$deal->id()]));

        $this->patch($deal->id(), ['field' => 'tier', 'value' => '', 'expected' => 'pro']);
        self::assertSame(['value' => null, 'display' => null, 'draft' => null], $this->answer());

        $this->patch($deal->id(), ['field' => 'venue', 'value' => $venue->id(), 'expected' => null]);
        self::assertSame(['value' => $venue->id(), 'display' => null, 'draft' => null], $this->answer());
    }

    public function test_a_tier_added_later_is_a_choice_at_once(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();
        $this->connection->executeStatement("INSERT INTO tiers (id, code, name, price_rappen, rank_weight, photos_max, rooms_max, videos_max, documents_max, contact_visible, last_minute_allowed, detailed_report, event_types_max) VALUES (gen_random_uuid(), 'team', 'Team', 49900, 5, 20, 5, 2, 2, TRUE, FALSE, FALSE, NULL)");

        $this->patch($deal->id(), ['field' => 'tier', 'value' => 'team', 'expected' => null]);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'team', 'display' => null, 'draft' => null], $this->answer());
    }

    public function test_an_unchanged_value_is_a_success_without_an_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['amountRappen' => 125000]);

        // The same amount in another spelling; a stale `expected` does not matter when nothing would change.
        $this->patch($deal->id(), ['field' => 'amount', 'value' => "1'250.00", 'expected' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => '125000', 'display' => "CHF 1'250", 'draft' => "1'250"], $this->answer());
        self::assertSame(0, $this->auditRows($deal->id()));
    }

    public function test_a_stale_field_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['name' => 'Premium 2027']);

        $this->patch($deal->id(), ['field' => 'name', 'value' => 'Neu', 'expected' => 'Damals']);

        self::assertResponseStatusCodeSame(409);
        self::assertArrayHasKey('', $this->errors());
        self::assertSame('Premium 2027', $this->connection->fetchOne('SELECT name FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedValues(): iterable
    {
        yield 'three decimals' => ['amount', '12.345'];
        yield 'above the limit' => ['amount', '100000000'];
        yield 'negative' => ['amount', '-5'];
        yield 'words' => ['amount', 'viel'];
        yield 'no such day' => ['expected_close', '2027-02-30'];
        yield 'not a date' => ['contract_start', '1.3.2027'];
        yield 'no such tier' => ['tier', 'gold'];
        yield 'a tier of the old enum' => ['tier', 'premium'];
        yield 'no id' => ['owner', 'andrea'];
        yield 'no id for a venue' => ['venue', '42'];
    }

    #[DataProvider('refusedValues')]
    public function test_a_bad_value_is_a_422_on_the_value(string $field, string $value): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->patch($deal->id(), ['field' => $field, 'value' => $value, 'expected' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
        self::assertSame(0, $this->auditRows($deal->id()));
    }

    public function test_an_empty_name_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['name' => 'Premium 2027']);

        $this->patch($deal->id(), ['field' => 'name', 'value' => '', 'expected' => 'Premium 2027']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_a_contract_ending_before_its_start_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['contractStartOn' => new \DateTimeImmutable('2027-01-01')]);

        $this->patch($deal->id(), ['field' => 'contract_end', 'value' => '2026-12-31', 'expected' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value' => ['Das Vertragsende liegt vor dem Vertragsbeginn.']], $this->errors());
    }

    public function test_a_venue_of_another_company_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::createOne();
        $deal = DealFactory::createOne();

        $this->patch($deal->id(), ['field' => 'venue', 'value' => $venue->id(), 'expected' => null]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value' => ['Dieser Eintrag gehört nicht zu dieser Location.']], $this->errors());
    }

    public function test_the_end_of_a_won_contract_moves_the_company_stage(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        $deal = DealFactory::new()->inStage('won')->create(['company' => $company, 'contractEndOn' => new \DateTimeImmutable('2099-12-31')]);

        $this->patch($deal->id(), ['field' => 'contract_end', 'value' => '2000-01-01', 'expected' => '2099-12-31']);

        self::assertResponseIsSuccessful();
        self::assertSame('former_customer', $this->connection->fetchOne('SELECT stage FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_the_stage_and_the_company_are_no_fields(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        foreach (['stage', 'company'] as $field) {
            $this->patch($deal->id(), ['field' => $field, 'value' => 'won', 'expected' => 'new']);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(['field'], array_keys($this->errors()));
        }
    }

    public function test_an_unknown_deal_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->patch(EntityId::generate(), ['field' => 'name', 'value' => 'x', 'expected' => null]);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne(['name' => 'Premium 2027']);

        $this->client->request('PATCH', '/admin/api/crm/deals/'.$deal->id(), server: ['CONTENT_TYPE' => 'application/json'], content: '{"field": "name", "value": "x", "expected": "Premium 2027"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Premium 2027', $this->connection->fetchOne('SELECT name FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $deal = DealFactory::createOne();

        $this->client->request('PATCH', '/admin/api/crm/deals/'.$deal->id(), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'any'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $id, array $body): void
    {
        $token = (string) $this->client->request('GET', '/admin')->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('PATCH', '/admin/api/crm/deals/'.$id, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        /** @var array<string, mixed> $answer */
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $answer;
    }

    /**
     * @return array<string, list<string>>
     */
    private function errors(): array
    {
        $errors = $this->answer()['errors'] ?? null;
        self::assertIsArray($errors);

        /* @var array<string, list<string>> $errors */
        return $errors;
    }

    private function auditRows(string $dealId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE subject_type = ? AND subject_id = ?', ['deal', $dealId]);
    }
}
