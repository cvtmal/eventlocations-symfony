<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The inline-edit endpoints (D10, D11, P1): one field per PATCH, the field's loaded value as its
 * version, an unchanged value without an audit row, every error as JSON.
 */
final class PropertyApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_save_answers_the_canonical_value_and_writes_one_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);

        $this->patch($this->companyUrl($company->id()), ['field' => 'phone', 'value' => '  +41 44 000 00 00 ', 'expected' => null]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['value' => '+41 44 000 00 00'], $this->answer());
        self::assertSame('+41 44 000 00 00', $this->connection->fetchOne('SELECT phone FROM crm_companies WHERE id = ?', [$company->id()]));
        self::assertSame(1, $this->auditRows('company', $company->id()));
    }

    public function test_a_contact_save_lower_cases_the_email(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['email' => null]);

        $this->patch($this->contactUrl($contact->id()), ['field' => 'email', 'value' => 'Anna.Muster@Example.CH', 'expected' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'anna.muster@example.ch'], $this->answer());
        self::assertSame(1, $this->auditRows('contact', $contact->id()));
    }

    public function test_an_unchanged_value_is_a_success_without_an_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);

        // A stale `expected` does not matter when nothing would change.
        $this->patch($this->companyUrl($company->id()), ['field' => 'name', 'value' => ' Seeblick Events ', 'expected' => 'Something else']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'Seeblick Events'], $this->answer());
        self::assertSame(0, $this->auditRows('company', $company->id()));
    }

    public function test_an_unchanged_contact_value_is_a_success_without_an_audit_row(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['lastName' => 'Muster']);

        $this->patch($this->contactUrl($contact->id()), ['field' => 'last_name', 'value' => ' Muster ', 'expected' => 'Something else']);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => 'Muster'], $this->answer());
        self::assertSame(0, $this->auditRows('contact', $contact->id()));
    }

    public function test_emptying_a_field_stores_null(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->withDetails()->create();
        $website = $company->website();

        $this->patch($this->companyUrl($company->id()), ['field' => 'website', 'value' => '  ', 'expected' => $website]);

        self::assertResponseIsSuccessful();
        self::assertSame(['value' => null], $this->answer());
    }

    public function test_a_stale_field_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);

        $this->patch($this->companyUrl($company->id()), ['field' => 'name', 'value' => 'Seeblick AG', 'expected' => 'Seeblick']);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertArrayHasKey('', $this->errors());
        self::assertSame('Seeblick Events', $this->connection->fetchOne('SELECT name FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_a_stale_contact_field_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['lastName' => 'Muster']);

        $this->patch($this->contactUrl($contact->id()), ['field' => 'last_name', 'value' => 'Beispiel', 'expected' => 'Damals']);

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertArrayHasKey('', $this->errors());
        self::assertSame('Muster', $this->connection->fetchOne('SELECT last_name FROM crm_contacts WHERE id = ?', [$contact->id()]));
        self::assertSame(0, $this->auditRows('contact', $contact->id()));
    }

    public function test_a_nul_character_is_a_422_on_the_value(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->patchRaw($this->companyUrl($company->id()), '{"field": "notes", "value": "a\u0000b", "expected": null}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_a_nul_character_in_expected_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->patchRaw($this->companyUrl($company->id()), '{"field": "notes", "value": "neu", "expected": "\u0000"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['expected'], array_keys($this->errors()));
    }

    public function test_invalid_utf8_is_a_json_422_not_an_html_400(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->patchRaw($this->companyUrl($company->id()), "{\"field\": \"notes\", \"value\": \"a\xFFb\", \"expected\": null}");

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function test_a_bad_email_is_a_422_on_the_value(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne();

        $this->patch($this->contactUrl($contact->id()), ['field' => 'email', 'value' => 'anna@', 'expected' => $contact->email()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
        self::assertSame(0, $this->auditRows('contact', $contact->id()));
    }

    public function test_a_taken_email_is_a_422_on_the_value(): void
    {
        $this->logInAs(AdminRole::Editor);
        ContactFactory::createOne(['email' => 'anna@example.ch']);
        $contact = ContactFactory::createOne();

        $this->patch($this->contactUrl($contact->id()), ['field' => 'email', 'value' => 'ANNA@example.ch', 'expected' => $contact->email()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_emptying_a_required_field_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);

        $this->patch($this->companyUrl($company->id()), ['field' => 'name', 'value' => '', 'expected' => 'Seeblick Events']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_an_unknown_stage_or_owner_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Prospect)->create();

        $this->patch($this->companyUrl($company->id()), ['field' => 'stage', 'value' => 'partner', 'expected' => 'prospect']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));

        // A well-formed id of no staff account: the handler's rule.
        $this->patch($this->companyUrl($company->id()), ['field' => 'owner', 'value' => EntityId::generate(), 'expected' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_an_unknown_field_is_a_422_on_the_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->patch($this->companyUrl($company->id()), ['field' => 'origin', 'value' => 'venue', 'expected' => 'manual']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['field'], array_keys($this->errors()));
    }

    public function test_a_value_that_is_not_a_string_is_a_422(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->patchRaw($this->companyUrl($company->id()), '{"field": "notes", "value": ["x"], "expected": null}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['value'], array_keys($this->errors()));
    }

    public function test_an_unknown_record_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->patch($this->companyUrl(EntityId::generate()), ['field' => 'name', 'value' => 'x', 'expected' => null]);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->patch($this->contactUrl(EntityId::generate()), ['field' => 'last_name', 'value' => 'x', 'expected' => null]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events']);

        $this->client->request('PATCH', $this->companyUrl($company->id()), server: ['CONTENT_TYPE' => 'application/json'], content: '{"field": "name", "value": "x", "expected": "Seeblick Events"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Seeblick Events', $this->connection->fetchOne('SELECT name FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_a_missing_token_is_refused_for_a_contact(): void
    {
        $this->logInAs(AdminRole::Editor);
        $contact = ContactFactory::createOne(['lastName' => 'Muster']);

        $this->client->request('PATCH', $this->contactUrl($contact->id()), server: ['CONTENT_TYPE' => 'application/json'], content: '{"field": "last_name", "value": "x", "expected": "Muster"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Muster', $this->connection->fetchOne('SELECT last_name FROM crm_contacts WHERE id = ?', [$contact->id()]));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $company = CompanyFactory::createOne();

        $this->client->request('PATCH', $this->companyUrl($company->id()), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'any'], content: '{}');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    private function companyUrl(string $id): string
    {
        return '/admin/api/crm/companies/'.$id;
    }

    private function contactUrl(string $id): string
    {
        return '/admin/api/crm/contacts/'.$id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $url, array $body): void
    {
        $this->patchRaw($url, (string) json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function patchRaw(string $url, string $body): void
    {
        $token = $this->token();
        $this->client->request('PATCH', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], content: $body);
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
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

    private function auditRows(string $subjectType, string $subjectId): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM admin_audit_log WHERE subject_type = ? AND subject_id = ?', [$subjectType, $subjectId]);
    }
}
