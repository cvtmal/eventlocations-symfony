<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Entity\Company;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The Location's "Akquise" tab (G11): the outreach fields as one audited form, the objection that suppresses the
 * Location for good, and a suppressed Location that cannot be deleted.
 */
final class CompanyOutreachTabTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_staff_save_the_outreach_fields_once(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', $this->url($company));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[aria-current="page"], .tabs__item--active, nav', 'Akquise');

        $form = $crawler->filter('form[data-outreach-form]')->form([
            'outreach[campaignCode]' => ' brief-2026-11 ',
            'outreach[cohort]' => 'B',
            'outreach[channel]' => 'postal',
            'outreach[basis]' => 'none',
            'outreach[sentOn]' => '2026-11-10',
            'outreach[returnedOn]' => '2026-11-14',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->url($company), 303);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[data-outreach-form]')->form());

        self::assertSame(
            ['campaign_code' => 'brief-2026-11', 'cohort' => 'B', 'contact_channel' => 'postal', 'contact_basis' => 'none', 'outreach_sent_on' => '2026-11-10', 'mail_returned_on' => '2026-11-14'],
            $this->connection()->fetchAssociative('SELECT campaign_code, cohort, contact_channel, contact_basis, outreach_sent_on, mail_returned_on FROM crm_companies WHERE id = ?', [$company->id()]),
        );
        // The second, unchanged save sends no command: one audit row, and it holds no value.
        self::assertSame(['{"field": "outreach"}'], $this->connection()->fetchFirstColumn("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'company' AND subject_id = ?", [$company->id()]));
    }

    public function test_an_address_as_campaign_code_or_a_return_before_the_send_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', $this->url($company));

        $this->client->submit($crawler->filter('form[data-outreach-form]')->form(['outreach[campaignCode]' => 'mia@example.ch', 'outreach[sentOn]' => '2026-11-10', 'outreach[returnedOn]' => '2026-11-01']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[data-outreach-form]', 'Nur Buchstaben, Ziffern');
        self::assertSelectorTextContains('form[data-outreach-form]', 'Die Retoure kann nicht vor dem Versand liegen.');
        self::assertNull($this->connection()->fetchOne('SELECT campaign_code FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_a_campaign_code_goes_with_letters_only(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', $this->url($company));

        $this->client->submit($crawler->filter('form[data-outreach-form]')->form(['outreach[campaignCode]' => 'brief-2026-11', 'outreach[channel]' => 'email']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[data-outreach-form]', 'Eine Kampagne gibt es nur für Briefe.');
    }

    public function test_an_objection_suppresses_the_location_for_good_and_it_cannot_be_deleted(): void
    {
        $this->logInAs();
        $company = CompanyFactory::createOne();
        ContactFactory::createOne(['company' => $company, 'email' => 'mia@example.ch']);
        $crawler = $this->client->request('GET', $this->url($company));

        $this->client->submit($crawler->filter('form[action$="/akquise/widerspruch"]')->form());
        self::assertResponseRedirects($this->url($company), 303);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-outreach-suppressed]');
        self::assertSelectorNotExists('form[action$="/akquise/widerspruch"]');
        self::assertSame(2, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM suppressions WHERE reason = 'objection'"));

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());
        $this->client->submit($crawler->filter('#company-delete form[action$="/loeschen"]')->form());
        self::assertResponseRedirects('/admin/crm/locations/'.$company->id(), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Diese Location steht auf der Sperrliste und bleibt gespeichert');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM crm_companies WHERE id = ?', [$company->id()]));
    }

    public function test_a_forged_objection_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('POST', $this->url($company).'/widerspruch', ['_token' => 'forged']);

        self::assertResponseRedirects($this->url($company), 303);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM suppressions'));
    }

    private function url(Company $company): string
    {
        return '/admin/crm/locations/'.$company->id().'/akquise';
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
