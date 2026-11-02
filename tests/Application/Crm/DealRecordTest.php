<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\ChangeDealLostReason;
use App\Crm\Command\LinkDealContact;
use App\Crm\Command\MoveDealStage;
use App\Crm\Command\UnlinkDealContact;
use App\Crm\Entity\Deal;
use App\Crm\ValueObject\LostReason;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * A deal's record page (D27, N3, N8): its fields, cards, forms and the delete, which is an admin's.
 */
final class DealRecordTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', $this->url(DealFactory::createOne()));

        self::assertResponseRedirects('/admin/login');
    }

    public function test_an_unknown_deal_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/deals/019a0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_page_shows_the_deal_with_its_company_venue_and_contacts(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick', 'tier' => Tier::Premium]);
        $company = CompanyFactory::new()->withVenues($venue)->create(['name' => 'Seeblick Events']);
        $laura = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier', 'company' => $company]);
        ContactFactory::createOne(['firstName' => 'Marc', 'lastName' => 'Widmer', 'company' => $company]);
        $deal = DealFactory::new()->inStage('offer')->create([
            'name' => 'Premium 2027',
            'company' => $company,
            'venue' => $venue,
            'tier' => Tier::Premium,
            'amountRappen' => 125050,
            'contractEndOn' => new \DateTimeImmutable('2027-12-31'),
            'owner' => $owner,
        ]);
        $this->link($deal->id(), $laura->id());

        $crawler = $this->client->request('GET', $this->url($deal));

        self::assertResponseIsSuccessful();
        self::assertSame('Premium 2027', $crawler->filter('h1')->text());
        self::assertSame('Angebot', $crawler->filter('.crm-record__identity .pill')->text());
        self::assertSame('/admin/crm/firmen/'.$company->id(), $crawler->filter('.crm-record__identity a')->attr('href'));
        self::assertSame('Seeblick Events', $crawler->filter('section[aria-labelledby="deal-company-title"] a')->text());
        self::assertSame('Laura Meier', $crawler->filter('#kontakte .crm-side__link')->text());
        self::assertSame('Schloss Seeblick', $crawler->filter('#location .crm-side__link')->text());
        self::assertSame('/admin/locations/'.$venue->number(), $crawler->filter('#location .crm-side__link')->attr('href'));
        self::assertSame('deal', $this->timelineProps($crawler)['kind']);
        self::assertSame('/admin/api/crm/deals/'.$deal->id().'/timeline', $this->timelineProps($crawler)['urls']['timeline'] ?? null);
        self::assertNull($this->timelineProps($crawler)['email']);
        self::assertSame(1, $crawler->filter('#deal_contact_contact option[value="'.$this->contactId($company->id(), 'Widmer').'"]')->count());
        self::assertSame(0, $crawler->filter('#deal_contact_contact option[value="'.$laura->id().'"]')->count());
    }

    public function test_the_fields_hold_the_stored_value_and_edit_an_amount_as_francs(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne([
            'amountRappen' => 125050,
            'tier' => Tier::Premium,
            'contractStartOn' => new \DateTimeImmutable('2027-01-01'),
        ]);

        $crawler = $this->client->request('GET', $this->url($deal));

        $fields = array_column($this->propertyProps($crawler)['fields'], null, 'name');
        self::assertSame(['name', 'amount', 'tier', 'venue', 'contract_start', 'contract_end', 'expected_close', 'owner'], array_keys($fields));
        self::assertSame(['amount', '125050', "CHF 1'250.50", "1'250.50"], [$fields['amount']['type'], $fields['amount']['value'], $fields['amount']['display'], $fields['amount']['draft']]);
        self::assertSame(['date', '2027-01-01', '01.01.2027'], [$fields['contract_start']['type'], $fields['contract_start']['value'], $fields['contract_start']['display']]);
        self::assertSame(['select', 'premium', 'Premium'], [$fields['tier']['type'], $fields['tier']['value'], $fields['tier']['display']]);
        self::assertNull($fields['contract_end']['value']);
        self::assertSame("CHF 1'250.50", $crawler->filter('.record-properties .property__value')->eq(1)->text());
    }

    public function test_the_venue_select_keeps_a_venue_the_company_lost(): void
    {
        $this->logInAs(AdminRole::Editor);
        $linked = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Alpenhof']);
        $gone = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Altes Haus']);
        $company = CompanyFactory::new()->withVenues($linked)->create();
        $deal = DealFactory::createOne(['company' => $company, 'venue' => $gone]);

        $crawler = $this->client->request('GET', $this->url($deal));

        $fields = array_column($this->propertyProps($crawler)['fields'], null, 'name');
        self::assertSame(['Alpenhof', 'Altes Haus'], array_column($fields['venue']['options'], 'label'));
    }

    public function test_a_deal_without_venue_and_contacts_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', $this->url(DealFactory::createOne()));

        self::assertSame('Keine Location gewählt.', $crawler->filter('#location .crm-side__empty')->text());
        self::assertSame('Noch keine Kontakte auf diesem Deal.', $crawler->filter('#kontakte .crm-side__empty')->text());
        self::assertCount(0, $crawler->filter('#kontakte .btn'));
    }

    public function test_an_editor_sees_no_delete(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', $this->url(DealFactory::createOne()));

        self::assertCount(0, $crawler->filter('[commandfor="deal-delete"]'));
        self::assertCount(0, $crawler->filter('dialog#deal-delete'));
    }

    public function test_an_editor_may_not_delete(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->client->request('POST', $this->url($deal).'/loeschen');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_admin_deletes_the_deal(): void
    {
        $this->logInAs(AdminRole::Admin);
        $deal = DealFactory::createOne(['name' => 'Premium 2027']);
        $id = $deal->id();
        TaskFactory::createOne(['deal' => $deal]);

        $crawler = $this->client->request('GET', $this->url($deal));
        $this->client->submit($crawler->filter('dialog#deal-delete form[action$="/loeschen"]')->form());

        self::assertResponseRedirects('/admin/crm/deals', 303);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_deals WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_tasks WHERE deal_id = ?', [$id]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Deal «Premium 2027» gelöscht.');
    }

    public function test_a_delete_without_its_token_deletes_nothing(): void
    {
        $this->logInAs(AdminRole::Admin);
        $deal = DealFactory::createOne();
        $id = $deal->id();

        $this->client->request('POST', $this->url($deal).'/loeschen');

        self::assertResponseRedirects('/admin/crm/deals/'.$id, 303);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_deals WHERE id = ?', [$id]));
    }

    public function test_a_contact_of_the_company_goes_on_the_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);

        $crawler = $this->client->request('GET', $this->url($deal));
        $this->client->submit($crawler->filter('#link-contact-form')->form(), ['deal_contact[contact]' => $contact->id()]);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id().'#kontakte', 303);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_deal_contacts WHERE deal_id = ? AND contact_id = ?', [$deal->id(), $contact->id()]));
        self::assertSame(1, $this->auditRows(LinkDealContact::class));
    }

    public function test_the_form_refuses_a_contact_it_does_not_offer(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();
        $other = ContactFactory::createOne(['company' => CompanyFactory::createOne()]);

        $crawler = $this->client->request('GET', $this->url($deal));
        $form = $crawler->filter('#link-contact-form')->form();
        $form->disableValidation()->setValues(['deal_contact[contact]' => $other->id()]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id().'#kontakte', 303);
        self::assertSame(0, $this->auditRows(LinkDealContact::class));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Bitte einen Kontakt aus der Liste wählen.');
    }

    public function test_a_contact_comes_off_the_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);
        $this->link($deal->id(), $contact->id());

        $crawler = $this->client->request('GET', $this->url($deal));
        $this->client->submit($crawler->filter('#kontakte form.crm-side__form')->form());

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id().'#kontakte', 303);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_deal_contacts WHERE deal_id = ?', [$deal->id()]));
        self::assertSame(1, $this->auditRows(UnlinkDealContact::class));
    }

    public function test_an_unlink_of_a_contact_not_on_the_deal_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);
        $this->link($deal->id(), $contact->id());

        $crawler = $this->client->request('GET', $this->url($deal));
        $form = $crawler->filter('#kontakte form.crm-side__form')->form();
        $this->connection()->executeStatement('DELETE FROM crm_deal_contacts WHERE deal_id = ?', [$deal->id()]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id().'#kontakte', 303);
        self::assertSame(0, $this->auditRows(UnlinkDealContact::class));
    }

    public function test_an_unlink_without_its_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $contact = ContactFactory::createOne(['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company]);
        $this->link($deal->id(), $contact->id());

        $this->client->request('POST', $this->url($deal).'/kontakte/'.$contact->id().'/entfernen');

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id().'#kontakte', 303);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_deal_contacts WHERE deal_id = ?', [$deal->id()]));
    }

    public function test_the_stage_form_moves_the_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->submitStage($deal, 'offer');

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('offer', $this->connection()->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(1, $this->auditRows(MoveDealStage::class));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Phase gespeichert.');
        self::assertSame('Angebot', $crawler->filter('.crm-record__identity .pill')->text());
        self::assertStringStartsWith('Andrea Keller hat den Deal «'.$deal->name().'» auf Angebot verschoben · ', $crawler->filter('.timeline-item--system')->text());
    }

    public function test_the_form_shows_the_current_stage_and_its_lost_reason(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create();

        $crawler = $this->client->request('GET', $this->url($deal));

        self::assertSame('lost', $crawler->filter('#deal_stage_stage option[selected]')->attr('value'));
        self::assertSame('budget', $crawler->filter('#deal_stage_lostReason option[selected]')->attr('value'));
        self::assertSame('lost', $crawler->filter('#deal_stage_expected')->attr('value'));
    }

    public function test_lost_needs_a_reason(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->submitStage($deal, 'lost');

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('new', $this->connection()->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Für «Verloren» bitte einen Grund wählen.');
    }

    public function test_lost_with_a_reason_moves_the_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->submitStage($deal, 'lost', 'competitor');

        self::assertSame(['stage_code' => 'lost', 'lost_reason' => 'competitor'], $this->connection()->fetchAssociative('SELECT stage_code, lost_reason FROM crm_deals WHERE id = ?', [$deal->id()]));
    }

    public function test_a_stale_form_is_a_flash(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $crawler = $this->client->request('GET', $this->url($deal));
        $form = $crawler->filter('form.deal-stage-form')->form(['deal_stage[stage]' => 'offer']);
        $this->connection()->executeStatement("UPDATE crm_deals SET stage_code = 'contacted' WHERE id = ?", [$deal->id()]);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame('contacted', $this->connection()->fetchOne('SELECT stage_code FROM crm_deals WHERE id = ?', [$deal->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Dieser Deal wurde inzwischen verschoben.');
    }

    public function test_the_same_stage_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->submitStage($deal, 'new');

        self::assertSame(0, $this->auditRows(MoveDealStage::class));
    }

    public function test_changing_only_the_lost_reason_saves_it_and_stays_lost(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('lost', new \DateTimeImmutable('2026-10-01 12:00:00'), LostReason::Other)->create();

        $this->submitStage($deal, 'lost', 'no_need');

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        $row = $this->connection()->fetchAssociative("SELECT stage_code, lost_reason, to_char(closed_at, 'YYYY-MM-DD') AS closed_on FROM crm_deals WHERE id = ?", [$deal->id()]);
        self::assertSame(['stage_code' => 'lost', 'lost_reason' => 'no_need', 'closed_on' => '2026-10-01'], $row);
        self::assertSame(1, $this->auditRows(ChangeDealLostReason::class));
        self::assertSame(0, $this->auditRows(MoveDealStage::class));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM crm_activity_deals WHERE deal_id = ?', [$deal->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Verlustgrund gespeichert.');
    }

    public function test_the_same_lost_reason_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create();

        $this->submitStage($deal, 'lost', 'budget');

        self::assertSame(0, $this->auditRows(ChangeDealLostReason::class));
    }

    public function test_a_lost_deal_keeps_its_reason_when_the_form_empties_it(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::new()->inStage('lost', reason: LostReason::Budget)->create();

        $this->submitStage($deal, 'lost');

        self::assertSame('budget', $this->connection()->fetchOne('SELECT lost_reason FROM crm_deals WHERE id = ?', [$deal->id()]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Für «Verloren» bitte einen Grund wählen.');
    }

    public function test_an_open_deal_ignores_a_reason_on_the_same_stage(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $this->submitStage($deal, 'new', 'budget');

        self::assertNull($this->connection()->fetchOne('SELECT lost_reason FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(0, $this->auditRows(ChangeDealLostReason::class));
    }

    public function test_a_form_from_before_the_deal_was_lost_leaves_the_reason_alone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $crawler = $this->client->request('GET', $this->url($deal));
        $form = $crawler->filter('form.deal-stage-form')->form(['deal_stage[stage]' => 'lost', 'deal_stage[lostReason]' => 'competitor']);
        $this->connection()->executeStatement("UPDATE crm_deals SET stage_code = 'lost', lost_reason = 'budget' WHERE id = ?", [$deal->id()]);
        $this->client->submit($form);

        self::assertSame('budget', $this->connection()->fetchOne('SELECT lost_reason FROM crm_deals WHERE id = ?', [$deal->id()]));
        self::assertSame(0, $this->auditRows(ChangeDealLostReason::class));
    }

    public function test_a_stage_the_rows_do_not_have_moves_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $deal = DealFactory::createOne();

        $crawler = $this->client->request('GET', $this->url($deal));
        $form = $crawler->filter('form.deal-stage-form')->form();
        $form->disableValidation()->setValues(['deal_stage[stage]' => 'archived']);
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/crm/deals/'.$deal->id(), 303);
        self::assertSame(0, $this->auditRows(MoveDealStage::class));
    }

    public function test_the_page_stays_within_its_statements(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Admin);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $contacts = ContactFactory::createMany(3, ['company' => $company]);
        $deal = DealFactory::createOne(['company' => $company, 'venue' => $venue, 'owner' => $owner]);
        $this->link($deal->id(), $contacts[0]->id());
        TaskFactory::createOne(['deal' => $deal]);

        // The deal with its company, venue and contacts, the owners with the sidebar's badge, the stages, the timeline's
        // activities, the Aufgaben card, the tier offer, the access-request badge; with the user's refresh 8 in production
        // (N14's ceiling 9).
        self::assertSame(7, $this->queriesOf($this->url($deal)));
    }

    private function url(Deal $deal): string
    {
        return '/admin/crm/deals/'.$deal->id();
    }

    private function submitStage(Deal $deal, string $stage, ?string $reason = null): void
    {
        $crawler = $this->client->request('GET', $this->url($deal));
        $this->client->submit($crawler->filter('form.deal-stage-form')->form(['deal_stage[stage]' => $stage, 'deal_stage[lostReason]' => $reason ?? '']));
    }

    private function link(string $dealId, string $contactId): void
    {
        $this->connection()->insert('crm_deal_contacts', ['deal_id' => $dealId, 'contact_id' => $contactId]);
    }

    private function contactId(string $companyId, string $lastName): string
    {
        return (string) $this->connection()->fetchOne('SELECT id FROM crm_contacts WHERE company_id = ? AND last_name = ?', [$companyId, $lastName]);
    }

    private function auditRows(string $command): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM admin_audit_log WHERE command = ?', [$command]);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineProps(Crawler $crawler): array
    {
        return $this->islandProps($crawler, 'timeline');
    }

    /**
     * @return array{fields: list<array<string, mixed>>}
     */
    private function propertyProps(Crawler $crawler): array
    {
        /** @var array{fields: list<array<string, mixed>>} $props */
        $props = $this->islandProps($crawler, 'record-properties');

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function islandProps(Crawler $crawler, string $name): array
    {
        /** @var array<string, mixed> $props */
        $props = json_decode((string) $crawler->filter('[data-island="'.$name.'"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);

        return $props;
    }
}
