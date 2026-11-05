<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\IngestInquiry;
use App\Crm\Entity\Company;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\InquiryFactory;
use App\Factory\TaskFactory;
use App\Factory\VenueFactory;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Command\PublishVenue;
use App\Venues\ValueObject\Tier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * A company record (CRM-1, CRM-3, D20): the properties card with its island and read-only fallback, the
 * Locations card (link, unlink) and the Kontakte card (its dialogs: `ContactCardTest`), and deletion for admins only.
 */
final class CompanyRecordTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;
    use ForgedPosts;

    public function test_the_page_shows_the_company_its_venues_and_contacts(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->withVenues($venue)->create(['name' => 'Seeblick Events GmbH', 'owner' => $owner]);
        $laura = ContactFactory::createOne(['firstName' => 'Laura', 'lastName' => 'Meier', 'company' => $company]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        self::assertSame('Seeblick Events GmbH', $crawler->filter('h1#record-title')->text());
        self::assertSame('SE', $crawler->filter('.crm-record__identity .avatar')->text());
        self::assertSame('Kunde', $crawler->filter('.crm-record__identity .pill')->text());
        self::assertSame(['CRM', 'Locations', 'Seeblick Events GmbH'], $crawler->filter('.breadcrumbs__item')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('Seeblick Events GmbH', $crawler->filter('.breadcrumbs__item [data-crm-follow="name"]')->text());
        self::assertSame('Locations', $crawler->filter('.sidebar__item[aria-current="page"]')->text());

        $props = json_decode((string) $crawler->filter('[data-island="record-properties"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('/admin/api/crm/companies/'.$company->id(), $props['url']);
        self::assertSame(['name', 'legal_name', 'website', 'phone', 'email', 'address', 'stage', 'owner', 'notes'], array_column($props['fields'], 'name'));
        $ownerField = $props['fields'][7];
        self::assertSame([$owner->id(), 'Andrea Keller'], [$ownerField['value'], $ownerField['display']]);
        self::assertSame('customer', $props['fields'][6]['value']);
        self::assertSame('Speichern', $props['labels']['save']);
        // The fallback shows the same values read-only.
        self::assertStringContainsString('Andrea Keller', $crawler->filter('[data-island="record-properties"] .record-properties')->text());
        self::assertStringContainsString('manuell erfasst', $crawler->filter('.crm-record__properties')->text());

        self::assertSame('Einträge (1)', $crawler->filter('#crm-venues-title')->text());
        self::assertSame('/admin/locations/'.$venue->number(), $crawler->filter('#locations .crm-side__link')->attr('href'));
        self::assertSame('Kontakte (1)', $crawler->filter('#crm-contacts-title')->text());
        // A contact has no page since #55: the name is text, the card's dialogs add and edit (ContactCardTest).
        self::assertSame('Laura Meier', $crawler->filter('#kontakte .crm-side__name')->text());
        self::assertCount(0, $crawler->filter('#kontakte .crm-side__link'));
        self::assertSame('contact-new', $crawler->filter('#kontakte .card__head button[command="show-modal"]')->attr('commandfor'));
        self::assertSame('contact-'.$laura->id(), $crawler->filter('#kontakte .crm-side__item button[command="show-modal"]')->attr('commandfor'));

        // An editor gets no Aktionen.
        self::assertSelectorNotExists('#record-actions');
        self::assertSelectorNotExists('#company-delete');
    }

    public function test_a_company_from_a_venue_without_a_website_renders_a_valid_head(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['origin' => CompanyOrigin::Venue]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('aus Eintrag', $crawler->filter('.crm-record__properties')->text());
        // `target` and `rel` need an `href`, and `crm-record.js` fills it after an inline save.
        self::assertSame('', $crawler->filter('.crm-record__website')->attr('href'));
        self::assertNotNull($crawler->filter('.crm-record__website')->attr('hidden'));
    }

    public function test_an_unknown_company_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/locations/'.EntityId::generate());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/crm/locations/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_venue_is_linked_through_the_dialog(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertSame('link-venue', $crawler->filter('#locations button[command="show-modal"]')->attr('commandfor'));
        $this->client->submit($crawler->filter('#link-venue-form')->form(['link_venue[venue]' => $venue->id()]));

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#locations', 303);
        self::assertSame($company->id(), $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venue->id()]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Eintrag verknüpft.');
        self::assertSame(['Schloss Seeblick'], $crawler->filter('#locations .crm-side__link')->each(static fn (Crawler $link): string => $link->text()));
    }

    public function test_the_link_dialog_opens_on_its_field(): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $props = json_decode((string) $crawler->filter('#link-venue [data-island="combobox"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertTrue($props['autofocus']);
        self::assertCount(1, $crawler->filter('#link-venue [data-island="combobox"] input[autofocus]'));
    }

    public function test_a_venue_of_another_company_is_refused_with_a_flash(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $other = CompanyFactory::new()->withVenues($venue)->create();
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#link-venue-form')->form(['link_venue[venue]' => $venue->id()]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'gehört schon zu einer anderen Location');
        self::assertSame($other->id(), $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_empty_or_unknown_venue_is_refused_with_a_flash(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());
        $form = $crawler->filter('#link-venue-form')->form();

        $this->client->submit($form, ['link_venue[venue]' => '']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Bitte einen Eintrag aus der Liste wählen.');

        $this->client->submit($form, ['link_venue[venue]' => EntityId::generate()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'existiert nicht mehr');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_company_venues'));
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_link_links_nothing(array $token, array $server): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne();
        $venue = VenueFactory::new()->withoutLocation(null)->create();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/eintraege', ['link_venue' => ['venue' => $venue->id()] + $token], server: $server);

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#locations', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_company_venues'));
        $this->client->followRedirect();
        self::assertSelectorExists('.toast--error');
    }

    public function test_linking_a_venue_again_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#link-venue-form')->form(['link_venue[venue]' => $venue->id()]));

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#locations', 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_company_venues'));
        self::assertSame(0, $this->auditRows($company->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Eintrag verknüpft.');
    }

    public function test_a_venue_is_unlinked(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $button = $crawler->filter('#locations button[aria-label="Schloss Seeblick von dieser Location lösen"]');
        self::assertCount(1, $button);
        $this->client->submit($button->form());

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#locations', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_company_venues'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('#locations', 'Noch kein Eintrag verknüpft.');
    }

    public function test_a_stale_unlink_says_so_and_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $form = $this->client->request('GET', '/admin/crm/locations/'.$company->id())->filter('#locations button[aria-label="Schloss Seeblick von dieser Location lösen"]')->form();
        $other = CompanyFactory::createOne();
        $this->connection->executeStatement('UPDATE crm_company_venues SET company_id = ?', [$other->id()]);

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id().'#locations', 303);
        self::assertSame($other->id(), $this->connection->fetchOne('SELECT company_id FROM crm_company_venues WHERE venue_id = ?', [$venue->id()]));
        self::assertSame(0, $this->auditRows($company->id()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast--error', 'Dieser Eintrag gehört nicht mehr zu dieser Location.');
    }

    public function test_an_unlink_on_a_deleted_company_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $form = $this->client->request('GET', '/admin/crm/locations/'.$company->id())->filter('#locations button[aria-label="Schloss Seeblick von dieser Location lösen"]')->form();
        $this->connection->executeStatement('DELETE FROM crm_companies');

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->auditRows($company->id()));
    }

    public function test_an_unlink_without_its_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create();
        $company = CompanyFactory::new()->withVenues($venue)->create();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/eintraege/'.$venue->id().'/entfernen');

        self::assertResponseRedirects(null, 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_company_venues'));
    }

    public function test_an_admin_deletes_a_company_and_its_contacts_go_with_it(): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne(['name' => 'Seeblick Events GmbH']);
        ContactFactory::createOne(['company' => $company]);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertSame('company-delete', $crawler->filter('#record-actions button.dropdown__item--danger')->attr('commandfor'));
        self::assertSelectorExists('#company-delete form[method="dialog"] button[autofocus]');
        $this->client->submit($crawler->filter('#company-delete form[action$="/loeschen"]')->form());

        self::assertResponseRedirects('/admin/crm/locations', 303);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_contacts'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Location «Seeblick Events GmbH» gelöscht.');
    }

    /**
     * @param array<string, string> $token
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_delete_deletes_nothing(array $token, array $server): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/loeschen', ['delete_company' => $token], server: $server);

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id(), 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
        $this->client->followRedirect();
        self::assertSelectorExists('.toast--error');
    }

    public function test_a_company_with_a_deal_stays_with_a_flash(): void
    {
        $this->logInAs(AdminRole::Admin);
        $company = CompanyFactory::createOne();
        DealFactory::createOne(['company' => $company]);
        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $this->client->submit($crawler->filter('#company-delete form[action$="/loeschen"]')->form());

        self::assertResponseRedirects('/admin/crm/locations/'.$company->id(), 303);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast', 'Diese Location hat Deals und lässt sich nicht löschen.');
    }

    public function test_a_derived_stage_is_read_only_and_says_where_it_comes_from(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        DealFactory::new()->inStage('won')->create(['company' => $company]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $props = json_decode((string) $crawler->filter('[data-island="record-properties"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        $stage = array_values(array_filter($props['fields'], static fn (array $field): bool => 'stage' === $field['name']))[0];
        self::assertTrue($stage['readonly']);
        self::assertSame('customer', $stage['value']);
        self::assertSame('Kunde (aus Deals)', $stage['display']);
    }

    public function test_a_hand_set_stage_stays_editable_with_an_open_deal(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->inStage(CompanyStage::Customer)->create();
        DealFactory::new()->inStage('offer')->create(['company' => $company]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $props = json_decode((string) $crawler->filter('[data-island="record-properties"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        $stage = array_values(array_filter($props['fields'], static fn (array $field): bool => 'stage' === $field['name']))[0];
        self::assertArrayNotHasKey('readonly', $stage);
        self::assertSame('Kunde', $stage['display']);
    }

    public function test_an_editor_may_not_delete(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $this->client->request('POST', '/admin/crm/locations/'.$company->id().'/loeschen');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM crm_companies'));
    }

    public function test_the_page_mounts_the_timeline_with_its_first_page(): void
    {
        ['user' => $author] = $this->logInAs(AdminRole::Editor, 'Andrea Keller');
        $company = CompanyFactory::createOne();
        for ($day = 1; $day <= TimelineQuery::PER_PAGE + 1; ++$day) {
            ActivityFactory::new()->onCompany($company)->create(['actor' => $author, 'body' => 'Eintrag '.$day, 'occurredAt' => new \DateTimeImmutable(\sprintf('2026-08-%02dT10:00:00+02:00', $day))]);
        }

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('.crm-record__center [data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('company', $props['kind']);
        self::assertSame($company->id(), $props['recordId']);
        self::assertSame($author->id(), $props['viewerId']);
        self::assertSame('/admin/api/crm/companies/'.$company->id().'/timeline', $props['urls']['timeline']);
        self::assertSame('/admin/api/crm/activities', $props['urls']['activities']);
        self::assertSame(['note', 'email_logged', 'email_sent', 'call', 'meeting', 'inquiry', 'deal_stage_changed'], array_column($props['types'], 'code'));
        self::assertSame(['Notizen', 'Erfasste E-Mails', 'Gesendete E-Mails', 'Anrufe', 'Meetings', 'Anfragen', 'Deal-Phasen'], array_column($props['types'], 'tab'));
        self::assertSame(['Notiz', 'E-Mail', 'Anruf', 'Meeting', 'Aufgabe'], array_column($props['composer'], 'label'));
        self::assertSame(['Andrea Keller'], array_column($props['assignees'], 'name'));
        self::assertSame('__type__ von __actor__', $props['labels']['by']);
        self::assertSame('«__venue__» publiziert', $props['labels']['system']['published']);

        // P6: 20 entries in the props, and the same 20 read-only without JS.
        self::assertCount(TimelineQuery::PER_PAGE, $props['page']['entries']);
        self::assertNotNull($props['page']['next']);
        self::assertSame('Eintrag 21', $props['page']['entries'][0]['body']);
        self::assertTrue($props['page']['entries'][0]['canEdit']);

        $fallback = $crawler->filter('[data-island="timeline"] .timeline-item');
        self::assertCount(TimelineQuery::PER_PAGE, $fallback);
        self::assertSame('Notiz von Andrea Keller', $fallback->first()->filter('.timeline-item__title')->text());
        self::assertSame('21.08.2026, 10:00', $fallback->first()->filter('.timeline-item__meta')->text());
        self::assertSame('Eintrag 21', $fallback->first()->filter('.muted')->text());

        self::assertSame(['note', 'email_logged', 'call', 'task'], $crawler->filter('.crm-record__card [data-composer-tab]')->each(static fn (Crawler $button): ?string => $button->attr('data-composer-tab')));
        self::assertSame(['Notiz', 'E-Mail', 'Anruf', 'Aufgabe'], $crawler->filter('.crm-record__card [data-composer-tab]')->each(static fn (Crawler $button): string => $button->text()));
    }

    public function test_a_body_reaches_the_page_only_as_text(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        ActivityFactory::new()->onCompany($company)->create(['body' => '<script>alert(1)</script><b>fett</b>']);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<b>fett', $html);
        self::assertSame('<script>alert(1)</script><b>fett</b>', $crawler->filter('[data-island="timeline"] .timeline-item .muted')->text());
        self::assertCount(0, $crawler->filter('[data-island="timeline"] b'));
    }

    public function test_the_fallback_shows_a_venue_event_and_an_erased_entry(): void
    {
        ['user' => $viewer] = $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $erased = ActivityFactory::new()->onCompany($company)->create(['body' => 'Geheim']);
        $erased->anonymise(new \DateTimeImmutable('2026-10-01T10:00:00+02:00'));
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();
        $entityManager->getConnection()->insert('admin_audit_log', [
            'id' => EntityId::generate(),
            'actor_type' => 'admin_user',
            'actor_id' => $viewer->id(),
            'command' => PublishVenue::class,
            'subject_type' => 'venue',
            'subject_id' => (string) $venue->number(),
            'changes' => '{}',
            'created_at' => '2026-10-29 11:48:00+01',
        ]);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $system = $crawler->filter('[data-island="timeline"] .timeline-item--system');
        self::assertCount(1, $system);
        self::assertStringContainsString('«Schloss Seeblick» publiziert · Andrea Keller · 29.10.2026, 11:48', $system->text());
        self::assertSame('Inhalt entfernt', $crawler->filter('[data-island="timeline"] .timeline-item--card .muted')->text());
        self::assertStringNotContainsString('Geheim', (string) $this->client->getResponse()->getContent());
    }

    public function test_an_inquiry_entry_reaches_the_island_and_the_fallback(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withoutLocation(null)->withContact()->create(['name' => 'Schloss Seeblick']);
        $company = CompanyFactory::new()->withVenues($venue)->create();
        $inquiry = InquiryFactory::createOne(['venue' => $venue, 'firstName' => 'Sina', 'lastName' => 'Huber']);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new IngestInquiry($inquiry->id()));

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('.crm-record__center [data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame('/admin/crm/anfragen', $props['urls']['inquiries']);
        self::assertSame('von __couple__ für __venue__', $props['labels']['inquiryEntry']);
        self::assertSame('Anfrage öffnen', $props['labels']['inquiryOpen']);
        self::assertSame($inquiry->id(), $props['page']['entries'][0]['inquiryId']);
        self::assertSame('Sina Huber', $props['page']['entries'][0]['inquiryCouple']);

        $fallback = $crawler->filter('[data-island="timeline"] .timeline-item--card');
        self::assertCount(1, $fallback);
        self::assertSame('Anfrage', $fallback->filter('.timeline-item__title')->text());
        self::assertSame('von Sina Huber für '.$inquiry->venueName(), $fallback->filter('.muted')->text());
        self::assertCount(0, $fallback->filter('a'), 'Without JS the timeline is read-only.');
    }

    public function test_the_fallback_shows_a_purged_and_an_erased_inquiry_entry(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        ActivityFactory::new()->ofType('inquiry')->onCompany($company)->create([
            'actor' => null, 'title' => 'Schloss Seeblick', 'body' => null, 'occurredAt' => new \DateTimeImmutable('2026-10-02T10:00:00+02:00'),
        ]);
        $erased = ActivityFactory::new()->ofType('inquiry')->onCompany($company)->create([
            'actor' => null, 'title' => 'Geheim', 'body' => null, 'occurredAt' => new \DateTimeImmutable('2026-10-01T10:00:00+02:00'),
        ]);
        $erased->anonymise(new \DateTimeImmutable('2026-10-03T10:00:00+02:00'));
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->flush();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        self::assertSame(['Schloss Seeblick', 'Inhalt entfernt'], $crawler->filter('[data-island="timeline"] .timeline-item--card .muted')->each(static fn (Crawler $body): string => $body->text()));
        self::assertStringNotContainsString('Geheim', (string) $this->client->getResponse()->getContent());
    }

    public function test_a_company_with_no_entries_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertSame('Noch keine Aktivitäten.', $crawler->filter('[data-island="timeline"] .crm-timeline')->text());
    }

    public function test_the_deals_card_shows_five_with_the_total_and_a_link_to_all(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        DealFactory::new()->inStage('offer')->create(['name' => 'Verlängerung 2027', 'company' => $company, 'tier' => Tier::Premium, 'amountRappen' => 480000, 'contractEndOn' => new \DateTimeImmutable('2027-12-31')]);
        DealFactory::new()->inStage('won')->many(5)->create(['company' => $company]);
        DealFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        $card = $crawler->filter('#deals');
        self::assertSame('Deals (6)', $card->filter('h2')->text());
        self::assertSame('/admin/crm/deals/neu?company='.$company->id(), $card->filter('.card__head a')->attr('href'));
        self::assertCount(5, $card->filter('.crm-side__item'));
        $first = $card->filter('.crm-side__item')->first();
        self::assertSame('Verlängerung 2027', $first->filter('.crm-side__link')->text());
        self::assertSame('Premium · bis 31.12.2027', $first->filter('.crm-side__detail')->text());
        self::assertSame('Angebot', $first->filter('.pill')->text());
        self::assertSame("CHF 4'800", $first->filter('.deal-side__amount')->text());
        self::assertSame('/admin/crm/locations/'.$company->id().'/deals', $card->filter('.deal-side__all a')->attr('href'));
    }

    public function test_a_company_without_deals_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertSame('Noch keine Deals.', $crawler->filter('#deals .crm-side__empty')->text());
        self::assertCount(0, $crawler->filter('.deal-side__all'));
    }

    public function test_the_tabs_lead_to_the_overview_and_all_deals(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        $url = '/admin/crm/locations/'.$company->id();
        $deals = DealFactory::createMany(6, ['company' => $company]);
        DealFactory::createOne(['name' => 'Fremd']);

        $crawler = $this->client->request('GET', $url);
        self::assertSame(['Übersicht', 'Deals'], $crawler->filter('.tabs--record .tab')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame('Übersicht', $crawler->filter('.tabs--record .tab--active')->text());

        $crawler = $this->client->request('GET', $url.'/deals');
        self::assertResponseIsSuccessful();
        self::assertSame('Deals', $crawler->filter('.tabs--record .tab--active')->text());
        self::assertSame($url, $crawler->filter('.tabs--record .tab')->first()->attr('href'));
        self::assertSame($company->name(), $crawler->filter('h1')->text());
        self::assertCount(6, $crawler->filter('.table tbody .crm-list__link'));
        self::assertCount(0, $crawler->filter('[data-composer-tab]'));
        self::assertSame(1, $crawler->filter('a[href="/admin/crm/deals/'.$deals[0]->id().'"]')->count());
    }

    public function test_the_deals_tab_of_a_company_without_deals_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id().'/deals');

        self::assertSame('Noch keine Deals.', $crawler->filter('.empty__title')->text());
    }

    public function test_the_deals_tab_of_an_unknown_company_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/crm/locations/019a0000-0000-7000-8000-000000000000/deals');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_deals_tab_stays_within_its_statements(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::createOne();
        DealFactory::createMany(3, ['company' => $company]);

        // The company, the owners, the deals, the access-request badge; with the user's refresh 5 in production (N14's ceiling 5).
        self::assertSame(4, $this->queriesOf('/admin/crm/locations/'.$company->id().'/deals'));
    }

    public function test_the_composer_can_write_to_the_company_and_its_contacts_with_an_address(): void
    {
        $this->logInAs(AdminRole::Editor);
        $company = CompanyFactory::new()->afterInstantiate(static fn (Company $company) => $company->changeEmail('info@seeblick.example'))->create(['name' => 'Seeblick Events GmbH']);
        $laura = ContactFactory::createOne(['company' => $company, 'firstName' => 'Laura', 'lastName' => 'Berger', 'email' => 'laura@seeblick.example']);
        ContactFactory::createOne(['company' => $company, 'lastName' => 'Keller', 'email' => null]);
        $mia = ContactFactory::createOne(['company' => $company, 'firstName' => 'Mia', 'lastName' => 'Zürcher', 'email' => 'mia@seeblick.example']);

        $crawler = $this->client->request('GET', '/admin/crm/locations/'.$company->id());

        self::assertResponseIsSuccessful();
        $props = json_decode((string) $crawler->filter('.crm-record__center [data-island="timeline"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame([
            ['contactId' => null, 'name' => 'Seeblick Events GmbH', 'email' => 'info@seeblick.example'],
            ['contactId' => $laura->id(), 'name' => 'Laura Berger', 'email' => 'laura@seeblick.example'],
            ['contactId' => $mia->id(), 'name' => 'Mia Zürcher', 'email' => 'mia@seeblick.example'],
        ], $props['email']['recipients']);
        self::assertSame(['send' => '/admin/api/crm/emails', 'draft' => '/admin/api/crm/email-draft'], $props['email']['urls']);
    }

    public function test_the_page_stays_within_its_statements(): void
    {
        ['user' => $owner] = $this->logInAs(AdminRole::Admin);
        $venues = VenueFactory::new()->withoutLocation(null)->many(2)->create();
        $company = CompanyFactory::new()->withVenues(...$venues)->afterInstantiate(static fn (Company $company) => $company->changeEmail('info@seeblick.example'))->create(['owner' => $owner]);
        ContactFactory::createMany(3, ['company' => $company]);
        TaskFactory::createMany(2, ['company' => $company]);

        // The company with its venues and contacts, the owners, which carry the sidebar's badge (`TaskBadge`), the
        // timeline's activities, venue names and audit rows, the composer's mail templates, the Aufgaben card, the Deals
        // card, the access-request badge; with the user's refresh 10 in production (N14's ceiling 10). The address is the
        // worst case: without one the templates are not read.
        self::assertSame(9, $this->queriesOf('/admin/crm/locations/'.$company->id()));
    }

    private function auditRows(string $companyId): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'company' AND subject_id = ?", [$companyId]);
    }
}
