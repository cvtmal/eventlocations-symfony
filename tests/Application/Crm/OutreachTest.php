<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Controller\OutreachController;
use App\Crm\CsvStream;
use App\Crm\Entity\Company;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\RecordedFunnelEventFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Shared\Funnel\FunnelEvent;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * G11's Akquise page and the suppression rule of every CRM export: a suppressed Location is absent from the Location,
 * contact and deal CSVs and from the print list; the cohort funnel renders.
 */
final class OutreachTest extends WebTestCase
{
    use AdminSession;
    use ClockSensitiveTrait;
    use Factories;

    private const string CAMPAIGN = 'brief-2026-11';

    public function test_a_suppressed_location_is_absent_from_every_export(): void
    {
        $this->logInAs();
        $kept = CompanyFactory::createOne(['name' => 'Seeblick Events']);
        $byLocation = CompanyFactory::createOne(['name' => 'Gesperrt Ganz']);
        $byContact = CompanyFactory::createOne(['name' => 'Gesperrt Kontakt']);
        foreach ([$kept, $byLocation, $byContact] as $company) {
            ContactFactory::createOne(['company' => $company, 'lastName' => 'Kontakt '.$company->name(), 'email' => strtolower(str_replace(' ', '.', $company->name())).'@example.ch']);
            DealFactory::createOne(['company' => $company, 'name' => 'Deal '.$company->name()]);
        }
        $this->connection()->insert('suppressions', ['id' => EntityId::generate(), 'company_id' => $byLocation->id(), 'reason' => 'objection']);
        $this->connection()->insert('suppressions', ['id' => EntityId::generate(), 'email' => 'gesperrt.kontakt@example.ch', 'reason' => 'opt_out']);

        foreach (['/admin/crm/locations/export', '/admin/crm/kontakte/export', '/admin/crm/deals/export'] as $url) {
            $this->client->request('GET', $url);
            $content = $this->content();

            self::assertStringContainsString('Seeblick Events', $content, $url);
            self::assertStringNotContainsString('Gesperrt', $content, $url);
        }
    }

    public function test_the_cohort_funnel_counts_steps_since_the_send_day(): void
    {
        self::mockTime('2026-11-20 10:00:00 Europe/Zurich');
        $this->logInAs(AdminRole::Editor);
        $sent = new \DateTimeImmutable('2026-11-10');
        $early = VenueFactory::createOne();
        $late = VenueFactory::createOne();
        CompanyFactory::new()->withVenue($early)->withOutreach(new Outreach(self::CAMPAIGN, OutreachCohort::A, ContactChannel::Postal, sentOn: $sent))->create();
        CompanyFactory::new()->withVenue($late)->withOutreach(new Outreach(self::CAMPAIGN, OutreachCohort::A, ContactChannel::Postal, sentOn: $sent, returnedOn: new \DateTimeImmutable('2026-11-15')))->create();
        CompanyFactory::new()->withOutreach(new Outreach(cohort: OutreachCohort::C, channel: ContactChannel::Email))->create();
        // Before the send day (Zurich midnight) counts nothing; on it and after counts once per venue.
        RecordedFunnelEventFactory::createOne(['event' => FunnelEvent::PreviewOpened, 'venueId' => $early->id(), 'occurredAt' => new \DateTimeImmutable('2026-11-09 23:30:00 Europe/Zurich')]);
        RecordedFunnelEventFactory::createOne(['event' => FunnelEvent::PreviewOpened, 'venueId' => $late->id(), 'occurredAt' => new \DateTimeImmutable('2026-11-10 00:10:00 Europe/Zurich')]);
        RecordedFunnelEventFactory::createOne(['event' => FunnelEvent::PreviewOpened, 'venueId' => $late->id(), 'occurredAt' => new \DateTimeImmutable('2026-11-11 08:00:00 Europe/Zurich')]);
        RecordedFunnelEventFactory::createOne(['event' => FunnelEvent::ClaimStarted, 'venueId' => $late->id(), 'occurredAt' => new \DateTimeImmutable('2026-11-12 08:00:00 Europe/Zurich')]);

        $crawler = $this->client->request('GET', '/admin/crm/akquise');

        self::assertResponseIsSuccessful();
        // Kohorte, Locations, Versandt, Retouren, Gesperrt, then the six steps.
        self::assertSame(['2', '2', '1', '0', '1', '1', '0', '0', '0', '0'], self::cells($crawler, 'A'));
        self::assertSame(['1', '0', '0', '0', '0', '0', '0', '0', '0', '0'], self::cells($crawler, 'C'));
        self::assertSame(['0', '0', '0', '0', '0', '0', '0', '0', '0', '0'], self::cells($crawler, 'D'));
        self::assertCount(1, $crawler->filter('tr[data-campaign="'.self::CAMPAIGN.'"]'));
    }

    public function test_the_print_list_issues_postal_links_and_leaves_out_who_must_not_get_a_letter(): void
    {
        self::mockTime('2026-11-20 10:00:00 Europe/Zurich');
        $this->logInAs();
        $printed = $this->location('Aaa Remise', 'Bahnhofstrasse 1');
        $this->location('Bbb Retoure', returnedOn: new \DateTimeImmutable('2026-11-18'));
        $suppressed = $this->location('Ccc Gesperrt');
        CompanyFactory::new()->withOutreach(new Outreach(self::CAMPAIGN))->create(['name' => 'Ddd Ohne Eintrag']);
        $this->location('Eee Andere Kampagne', campaign: 'brief-2027-01');
        ContactFactory::createOne(['company' => $printed, 'firstName' => 'Mia', 'lastName' => 'Muster']);
        $this->connection()->insert('suppressions', ['id' => EntityId::generate(), 'company_id' => $suppressed->id(), 'reason' => 'opt_out']);

        $records = $this->print();

        self::assertSame(['Location', 'Kontakt', 'Adresse (Location)', 'Eintrag', 'Strasse (Eintrag)', 'PLZ', 'Ort', 'Kohorte', 'Kurzlink', 'Vorschau-Link', 'Gültig bis'], $records[0]);
        self::assertCount(2, $records);
        self::assertSame(['Aaa Remise', 'Mia Muster', 'Bahnhofstrasse 1'], \array_slice($records[1], 0, 3));
        self::assertSame('A', $records[1][7]);
        self::assertMatchesRegularExpression('~^http://localhost/v/[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{10}$~', $records[1][8]);
        self::assertMatchesRegularExpression('~^http://localhost/vorschau/[A-Za-z0-9_-]{44}$~', $records[1][9]);
        self::assertSame('19.05.2027', $records[1][10]);
        self::assertSame(
            [['campaign_code' => self::CAMPAIGN, 'n' => 1]],
            $this->connection()->fetchAllAssociative('SELECT campaign_code, COUNT(*) AS n FROM venue_previews GROUP BY campaign_code'),
        );

        // A second print list reuses the live link and issues none.
        self::assertSame($records, $this->print());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM venue_previews'));
    }

    public function test_a_link_that_ends_within_thirty_days_is_replaced_on_the_print_list(): void
    {
        self::mockTime('2026-11-20 10:00:00 Europe/Zurich');
        $this->logInAs();
        $company = $this->location('Aaa Remise');
        $venue = $company->venue();
        self::assertNotNull($venue);
        VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => self::CAMPAIGN, 'at' => new \DateTimeImmutable('2026-11-01'), 'expiresAt' => new \DateTimeImmutable('2026-12-01')]);

        $records = $this->print();

        self::assertSame('19.05.2027', $records[1][10]);
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM venue_previews'));
    }

    public function test_only_an_admin_prints_and_every_post_needs_its_token(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->client->request('POST', '/admin/crm/akquise/druckliste', ['_token' => $this->token(), 'campaign' => self::CAMPAIGN]);
        self::assertResponseStatusCodeSame(403);

        $this->logInAs();
        $this->client->request('POST', '/admin/crm/akquise/druckliste', ['_token' => 'forged', 'campaign' => self::CAMPAIGN]);
        self::assertResponseRedirects('/admin/crm/akquise#kampagnen', 303);
        $this->client->request('POST', '/admin/crm/akquise/druckliste', ['_token' => $this->token(), 'campaign' => 'mia@example.ch']);
        self::assertResponseRedirects('/admin/crm/akquise#kampagnen', 303);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM venue_previews'));
    }

    public function test_recording_the_send_day_stamps_only_locations_without_one(): void
    {
        $this->logInAs(AdminRole::Editor);
        $open = $this->location('Aaa Offen');
        $done = $this->location('Bbb Schon', sentOn: new \DateTimeImmutable('2026-11-01'));
        $unprinted = $this->location('Ccc Ohne Link');
        $returned = $this->location('Ddd Retoure', returnedOn: new \DateTimeImmutable('2026-11-15'));
        foreach ([$open, $done, $returned] as $company) {
            VenuePreviewFactory::createOne(['venue' => $company->venue(), 'campaignCode' => self::CAMPAIGN]);
        }

        $this->client->request('POST', '/admin/crm/akquise/versand', ['_token' => $this->token(), 'campaign' => self::CAMPAIGN, 'sent_on' => '2026-11-19']);

        self::assertResponseRedirects('/admin/crm/akquise#kampagnen', 303);
        self::assertSame('2026-11-19', $this->connection()->fetchOne('SELECT outreach_sent_on FROM crm_companies WHERE id = ?', [$open->id()]));
        self::assertSame('2026-11-01', $this->connection()->fetchOne('SELECT outreach_sent_on FROM crm_companies WHERE id = ?', [$done->id()]));
        // No letter went to a Location without a link of the campaign or with a returned letter.
        self::assertNull($this->connection()->fetchOne('SELECT outreach_sent_on FROM crm_companies WHERE id = ?', [$unprinted->id()]));
        self::assertNull($this->connection()->fetchOne('SELECT outreach_sent_on FROM crm_companies WHERE id = ?', [$returned->id()]));
        self::assertSame('{"sent_on": "2026-11-19"}', $this->connection()->fetchOne("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'campaign'"));
    }

    public function test_staff_add_an_address_to_the_suppression_list_and_the_audit_row_never_holds_it(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('POST', '/admin/crm/akquise/sperrliste', ['_token' => $this->token(), 'email' => ' Mia@Example.CH ']);
        $this->client->request('POST', '/admin/crm/akquise/sperrliste', ['_token' => $this->token(), 'email' => 'mia@example.ch']);
        $this->client->request('POST', '/admin/crm/akquise/sperrliste', ['_token' => $this->token(), 'email' => 'keine-adresse']);

        self::assertSame(['mia@example.ch'], $this->connection()->fetchFirstColumn('SELECT email FROM suppressions'));
        self::assertStringNotContainsString('@', (string) $this->connection()->fetchOne("SELECT string_agg(changes::text || subject_id, ' ') FROM admin_audit_log WHERE subject_type = 'suppression'"));
        $crawler = $this->client->request('GET', '/admin/crm/akquise');
        self::assertSame('mia@example.ch', trim($crawler->filter('table[data-suppressions] tbody td')->first()->text()));
    }

    /**
     * @return list<string>
     */
    private static function cells(Crawler $crawler, string $cohort): array
    {
        return $crawler->filter('table[data-cohort-funnel] tr[data-cohort="'.$cohort.'"] td')->each(static fn (Crawler $cell): string => trim($cell->text()));
    }

    private function location(string $name, ?string $address = null, ?\DateTimeImmutable $returnedOn = null, ?\DateTimeImmutable $sentOn = null, string $campaign = self::CAMPAIGN): Company
    {
        $company = CompanyFactory::new()
            ->withVenue(VenueFactory::createOne())
            ->withOutreach(new Outreach($campaign, OutreachCohort::A, ContactChannel::Postal, sentOn: $sentOn, returnedOn: $returnedOn))
            ->afterInstantiate(static fn (Company $company) => $company->changeAddress($address))
            ->create(['name' => $name]);

        return $company;
    }

    /**
     * @return list<list<string>>
     */
    private function print(): array
    {
        $this->client->request('POST', '/admin/crm/akquise/druckliste', ['_token' => $this->token(), 'campaign' => self::CAMPAIGN]);
        $content = $this->content();
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=druckliste-'.self::CAMPAIGN.'-2026-11-20.csv');

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, substr($content, \strlen(CsvStream::BOM)));
        rewind($stream);
        $records = [];
        while (false !== ($record = fgetcsv($stream, null, CsvStream::SEPARATOR, CsvStream::ENCLOSURE, CsvStream::ESCAPE))) {
            $records[] = array_map(static fn (?string $cell): string => (string) $cell, $record);
        }
        fclose($stream);

        return $records;
    }

    private function token(): string
    {
        $crawler = $this->client->request('GET', '/admin/crm/akquise');
        $token = $crawler->filter('form[action="/admin/crm/akquise/sperrliste"] input[name="_token"]')->attr('value');
        self::assertNotNull($token, OutreachController::TOKEN);

        return $token;
    }

    private function content(): string
    {
        self::assertResponseIsSuccessful();

        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
