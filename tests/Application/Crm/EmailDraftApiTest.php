<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\EmailTemplate;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A template filled for the composer at pick time (CRM-10). The query's ids reach `uuid` columns, so a malformed one has
 * to end in a 422, never in Postgres.
 */
final class EmailDraftApiTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string URL = '/admin/api/crm/email-draft';
    private const string UUID = '0199f0d0-0000-7000-8000-000000000001';

    public function test_a_company_draft_merges_the_chosen_contact_the_company_and_its_venue(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenue($venue)->create(['name' => 'Seeblick Events GmbH']);
        $contact = ContactFactory::createOne(['firstName' => 'Laura', 'company' => $company]);
        $template = $this->template('Grüezi {contact.first_name}', "{company.name}\n\n{venue.name}");

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => $template, 'contact' => $contact->id()]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['subject' => 'Grüezi Laura', 'body' => "Seeblick Events GmbH\n\nSchloss Seeblick"], $this->answer());
    }

    public function test_a_missing_value_is_empty_and_an_empty_contact_is_none(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $template = $this->template('Grüezi {contact.first_name}', 'In {venue.name}.');

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => $template, 'contact' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame(['subject' => 'Grüezi ', 'body' => 'In .'], $this->answer());
    }

    public function test_an_unknown_token_stays_literal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $template = $this->template('{contact.last_name}', '{{ 7*7 }} {company.name}');

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => $template]);

        self::assertResponseIsSuccessful();
        self::assertSame(['subject' => '{contact.last_name}', 'body' => '{{ 7*7 }} Seeblick Events GmbH'], $this->answer());
    }

    public function test_a_subject_over_the_limit_after_merging_is_returned_as_it_is(): void
    {
        $this->logInAs(AdminRole::Editor);
        $name = str_repeat('Seeblick', 12);
        $company = CompanyFactory::createOne(['name' => $name]);
        $subject = str_repeat('x', 150).' {company.name}';
        $template = $this->template($subject, 'Text');

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => $template]);

        self::assertResponseIsSuccessful();
        self::assertSame(str_repeat('x', 150).' '.$name, $this->answer()['subject']);
    }

    public function test_an_unknown_template_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => self::UUID]);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_unknown_record_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $template = $this->template('Betreff', 'Text');

        $this->draft(['kind' => 'company', 'recordId' => self::UUID, 'template' => $template]);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_contact_of_another_company_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => CompanyFactory::createOne()]);
        $template = $this->template('Betreff', 'Text');

        $this->draft(['kind' => 'company', 'recordId' => $company->id(), 'template' => $template, 'contact' => $contact->id()]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kindsWithoutADraft(): iterable
    {
        yield 'a deal' => ['deal'];
        yield 'a contact, which has no record since #55' => ['contact'];
    }

    #[DataProvider('kindsWithoutADraft')]
    public function test_a_kind_without_a_draft_is_a_422_on_kind(string $kind): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->draft(['kind' => $kind, 'recordId' => self::UUID, 'template' => self::UUID]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['kind'], array_keys($this->answer()['errors']));
    }

    public function test_no_query_is_a_422_on_the_required_fields(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->draft([]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['kind', 'recordId', 'template'], array_keys($this->answer()['errors']));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedIds(): iterable
    {
        yield 'recordId no uuid' => ['recordId', 'abc'];
        yield 'recordId blank' => ['recordId', ''];
        yield 'recordId an array' => ['recordId', ['a']];
        yield 'recordId with a NUL' => ['recordId', "0199f0d0-0000-7000-8000-00000000000\0"];
        yield 'recordId invalid UTF-8' => ['recordId', "\xFF"];
        yield 'template no uuid' => ['template', 'abc'];
        yield 'template blank' => ['template', ''];
        yield 'template an array' => ['template', ['a']];
        yield 'contact no uuid' => ['contact', 'abc'];
        yield 'contact an array' => ['contact', ['a']];
    }

    #[DataProvider('malformedIds')]
    public function test_a_malformed_id_is_a_422_on_its_field(string $field, mixed $value): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->draft([$field => $value] + ['kind' => 'company', 'recordId' => self::UUID, 'template' => self::UUID]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([$field], array_keys($this->answer()['errors']));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $this->draft(['kind' => 'company', 'recordId' => self::UUID, 'template' => self::UUID]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function draft(array $query): void
    {
        $this->client->request('GET', self::URL, $query);
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

    private function template(string $subject, string $body): string
    {
        $id = EntityId::generate();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist(new EmailTemplate($id, 'Vorlage '.$id, $subject, $body));
        $entityManager->flush();

        return $id;
    }
}
