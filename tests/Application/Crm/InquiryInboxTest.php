<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\IngestInquiry;
use App\Crm\Exception\InquiryNotFound;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Factory\VenueFactory;
use App\Inquiries\ValueObject\FeedbackOutcome;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Zenstruck\Foundry\Test\Factories;

/**
 * The inbox (CRM-9, N4): the tabs, the list, one inquiry in full, and the status, which staff set and a look never does.
 */
final class InquiryInboxTest extends WebTestCase
{
    use AdminSession;
    use CountsQueries;
    use Factories;
    use FailsCommands;
    use ForgedPosts;

    private const string LIST = '/admin/crm/anfragen';
    private const string CSRF = 'csrf-token';

    public function test_the_tabs_show_their_counts_and_neu_opens_by_default(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry();
        $this->inquiry();
        $this->inquiry(status: InquiryStatus::Seen);
        $this->inquiry(status: InquiryStatus::Done);
        InquiryFactory::createOne();

        $crawler = $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['Neu2', '2', self::LIST.'?status=new'], ['Gesehen1', '1', self::LIST.'?status=seen'], ['Erledigt1', '1', self::LIST.'?status=done'], ['Alle4', '4', self::LIST.'?status=all']],
            $crawler->filter('.tabs .tab')->each(static fn (Crawler $tab): array => [
                $tab->text(),
                $tab->filter('.pill')->text(),
                (string) $tab->attr('href'),
            ]),
        );
        self::assertSame('Neu2', $crawler->filter('.tab[aria-current="page"]')->text());
        self::assertSame(1, $crawler->filter('.tab .pill--pink')->count());
        self::assertSame('2', $crawler->filter('.tab--active .pill--pink')->text());
        self::assertCount(2, $crawler->filter('.crm-inbox__row'));
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('tabs')]
    public function test_a_tab_lists_its_status(string $query, array $names): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry('2026-10-01 10:00:00', fields: ['firstName' => 'Neu', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-02 10:00:00', InquiryStatus::Seen, ['firstName' => 'Seen', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-03 10:00:00', InquiryStatus::Done, ['firstName' => 'Done', 'lastName' => 'Paar']);

        $crawler = $this->client->request('GET', self::LIST.$query);

        self::assertResponseIsSuccessful();
        self::assertSame($names, $crawler->filter('.crm-inbox__name')->each(static fn (Crawler $name): string => $name->text()));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tabs(): iterable
    {
        yield 'no parameter' => ['', ['Neu Paar']];
        yield 'neu' => ['?status=new', ['Neu Paar']];
        yield 'gesehen' => ['?status=seen', ['Seen Paar']];
        yield 'erledigt' => ['?status=done', ['Done Paar']];
        yield 'alle, newest first' => ['?status=all', ['Done Paar', 'Seen Paar', 'Neu Paar']];
        yield 'an unknown value' => ['?status=nope', ['Neu Paar']];
        yield 'an array' => ['?status[]=done', ['Neu Paar']];
    }

    public function test_a_row_shows_name_date_venue_guests_and_event_date(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry('2026-10-30 23:30:00', fields: [
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'venue' => VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']),
            'guestCount' => 1200,
            'eventDate' => new \DateTimeImmutable('2027-06-12'),
            'company' => 'Muster AG',
        ]);

        $row = $this->client->request('GET', self::LIST)->filter('.crm-inbox__row');

        self::assertSame('Sina Huber, Muster AG', $row->filter('.crm-inbox__name')->text());
        self::assertSame('31.10.2026', $row->filter('.crm-inbox__date')->text(), 'The date is Zurich\'s, and absolute.');
        self::assertStringContainsString('Schloss Seeblick', $row->text());
        self::assertSame('Firmenanlass · 1\'200 Gäste · 12.06.2027', $row->filter('.crm-inbox__facts-line')->text());
        self::assertSame(self::LIST.'/'.$id.'?status=new#anfrage-detail', $row->attr('href'));
        self::assertCount(1, $row->filter('.crm-inbox__dot--new[aria-label="Neu"]'));
    }

    public function test_a_row_without_event_date_shows_the_guests_and_only_a_new_one_has_a_dot(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry(status: InquiryStatus::Seen, fields: ['guestCount' => 80]);

        $crawler = $this->client->request('GET', self::LIST.'?status=seen');

        self::assertSame('Firmenanlass · 80 Gäste', $crawler->filter('.crm-inbox__facts-line')->text());
        self::assertSelectorNotExists('.crm-inbox__dot--new');
    }

    public function test_a_row_keeps_the_tab_and_the_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $ids = [];
        for ($i = 0; $i < 51; ++$i) {
            $ids[] = $this->inquiry(\sprintf('2026-09-01 10:%02d:00', $i), InquiryStatus::Seen);
        }

        $crawler = $this->client->request('GET', self::LIST.'?status=seen&page=2');

        $row = $crawler->filter('.crm-inbox__row');
        self::assertCount(1, $row);
        self::assertSame(self::LIST.'/'.$ids[0].'?status=seen&page=2#anfrage-detail', $row->attr('href'));
        self::assertSame('Zurück', $crawler->filter('.pagination a[rel="prev"]')->text());
    }

    public function test_the_first_row_fills_the_pane(): void
    {
        $this->logInAs(AdminRole::Editor);
        $older = $this->inquiry('2026-10-01 10:00:00', fields: ['firstName' => 'Alt', 'lastName' => 'Paar']);
        $newer = $this->inquiry('2026-10-02 10:00:00', fields: ['firstName' => 'Jung', 'lastName' => 'Paar']);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertSame('Jung Paar', $crawler->filter('#anfrage-detail h2')->text());
        self::assertStringContainsString($newer, (string) $crawler->filter('.crm-inbox__row[aria-current="true"]')->attr('href'));
        self::assertStringNotContainsString($older, (string) $crawler->filter('.crm-inbox__row[aria-current="true"]')->attr('href'));
    }

    public function test_an_empty_tab_says_so_and_has_no_pane(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry(status: InquiryStatus::Done);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSame('Keine Anfragen', $crawler->filter('.empty__title')->text());
        self::assertSelectorNotExists('#anfrage-detail');
    }

    public function test_the_detail_shows_every_field(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']);
        $id = $this->inquiry('2026-10-30 10:00:00', InquiryStatus::Seen, [
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'venue' => $venue,
            'eventType' => 'general_assembly',
            'company' => 'Wohnbaugenossenschaft Linde',
            'guestCount' => 80,
            'eventDate' => new \DateTimeImmutable('2027-06-12'),
            'dateFlexible' => true,
            'startTime' => new \DateTimeImmutable('1970-01-01 19:00'),
            'cateringWish' => 'in_house',
            'budgetChf' => 6500,
            'email' => 'sina@example.ch',
            'phone' => '+41 79 000 00 01',
            'message' => "Liebes Team\n\nist der Juni frei?",
        ]);

        $crawler = $this->client->request('GET', self::LIST.'/'.$id.'?status=new');

        $detail = $crawler->filter('#anfrage-detail');
        self::assertResponseIsSuccessful();
        self::assertSame('Sina Huber', $detail->filter('h2')->text());
        self::assertSame('Anfrage für Schloss Seeblick · 30.10.2026', $detail->filter('.crm-inbox__title .muted')->text());
        self::assertSame('/admin/locations/'.$venue->number(), $detail->filter('.crm-inbox__title a')->attr('href'));
        self::assertSame([
            'Anlass' => 'Generalversammlung',
            'Firma' => 'Wohnbaugenossenschaft Linde',
            'Anzahl Gäste' => '80',
            'Wunschtermin' => '12.06.2027 (flexibel)',
            'Beginn' => '19:00',
            'Catering' => 'Hauseigenes Catering',
            'Budget' => 'CHF 6\'500',
            'E-Mail' => 'sina@example.ch',
            'Telefon' => '+41 79 000 00 01',
        ], $this->facts($detail));
        self::assertSame('mailto:sina@example.ch', $detail->filter('.crm-inbox__facts a')->attr('href'));
        // The organizer is no CRM contact (S13): the reply button writes to the inquiry's address.
        $reply = $detail->filter('.crm-inbox__actions a');
        self::assertSame(['Per E-Mail antworten', 'mailto:sina@example.ch'], [$reply->text(), $reply->attr('href')]);
        self::assertCount(0, $detail->filter('a[href*="/admin/crm/kontakte"]'));
        self::assertSame("Liebes Team\n\nist der Juni frei?", $detail->filter('.crm-inbox__message')->text(null, false));
        self::assertSame('seen', $detail->filter('#inquiry-status option[selected]')->attr('value'));
        self::assertSame(['new', 'seen', 'done'], $detail->filter('#inquiry-status option')->each(static fn (Crawler $option): ?string => $option->attr('value')));
        self::assertStringContainsString('12 Monaten', $detail->filter('.crm-inbox__retention')->text());
    }

    public function test_a_flexible_date_says_so_and_empty_facts_show_a_dash(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry();

        $facts = $this->facts($this->client->request('GET', self::LIST)->filter('#anfrage-detail'));

        self::assertSame('flexibel', $facts['Wunschtermin']);
        foreach (['Firma', 'Beginn', 'Catering', 'Budget', 'Telefon'] as $label) {
            self::assertSame('–', $facts[$label], $label);
        }
    }

    public function test_the_open_inquiry_need_not_be_in_the_tab(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry(fields: ['firstName' => 'Neu', 'lastName' => 'Paar']);
        $done = $this->inquiry(status: InquiryStatus::Done, fields: ['firstName' => 'Done', 'lastName' => 'Paar']);

        $crawler = $this->client->request('GET', self::LIST.'/'.$done.'?status=new');

        self::assertSame('Done Paar', $crawler->filter('#anfrage-detail h2')->text());
        self::assertSame(['Neu Paar'], $crawler->filter('.crm-inbox__name')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSelectorNotExists('.crm-inbox__row[aria-current="true"]');
    }

    public function test_the_detail_shows_the_guests_feedback_only_to_staff(): void
    {
        $this->logInAs(AdminRole::Editor);
        $unanswered = $this->inquiry();
        InquiryFeedbackFactory::createOne(['inquiry' => InquiryFactory::find($unanswered)]);
        $answered = $this->inquiry('2026-10-02 10:00:00');
        InquiryFeedbackFactory::new()
            ->answered(false, FeedbackOutcome::Open, new \DateTimeImmutable('2026-10-10 10:00:00 UTC'))
            ->optedOut()
            ->create(['inquiry' => InquiryFactory::find($answered)]);
        $none = $this->inquiry('2026-10-03 10:00:00');
        $skipped = $this->inquiry('2026-10-04 10:00:00');
        InquiryFeedbackFactory::new()->optedOut(skipped: true)->create(['inquiry' => InquiryFactory::find($skipped)]);

        $crawler = $this->client->request('GET', self::LIST.'/'.$answered);
        self::assertSame(
            ['Rückmeldung des Veranstalters', 'Location hat sich gemeldet: Nein', 'Hat geklappt: Noch offen', 'Der Veranstalter möchte keine solchen Rückfragen mehr.'],
            $crawler->filter('.crm-inbox__block')->reduce(static fn (Crawler $block): bool => str_starts_with($block->text(), 'Rückmeldung'))->filter('h3, p')->each(static fn (Crawler $node): string => $node->text()),
        );
        $crawler = $this->client->request('GET', self::LIST.'/'.$unanswered);
        self::assertStringContainsString('Rückfrage gesendet, noch keine Antwort.', $crawler->filter('#anfrage-detail')->text());
        $crawler = $this->client->request('GET', self::LIST.'/'.$skipped);
        self::assertStringContainsString('Keine Rückfrage gesendet, der Veranstalter hat sie abbestellt.', $crawler->filter('#anfrage-detail')->text());
        $crawler = $this->client->request('GET', self::LIST.'/'.$none);
        self::assertStringNotContainsString('Rückmeldung des Veranstalters', $crawler->filter('#anfrage-detail')->text());
    }

    public function test_an_unknown_or_unlinked_inquiry_is_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $unlinked = InquiryFactory::createOne()->id();

        $this->client->request('GET', self::LIST.'/'.EntityId::generate());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', self::LIST.'/'.$unlinked);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', self::LIST.'/kein-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $pills
     */
    #[DataProvider('venueMails')]
    public function test_the_list_shows_a_pill_only_when_the_venue_mail_is_not_sent(string $mail, array $pills): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->mailStatus($this->inquiry(), $mail, 'skipped');

        $crawler = $this->client->request('GET', self::LIST);

        self::assertSame($pills, $crawler->filter('.crm-inbox__row .pill')->each(static fn (Crawler $pill): string => $pill->attr('class').': '.$pill->text()));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function venueMails(): iterable
    {
        yield 'sent' => ['sent', []];
        yield 'pending' => ['pending', ['pill pill--amber: Zustellung an die Location ausstehend']];
        yield 'failed' => ['failed', ['pill pill--red: Zustellung an die Location fehlgeschlagen']];
    }

    /**
     * @param list<string> $pills
     */
    #[DataProvider('bothMails')]
    public function test_the_detail_shows_both_mails(string $venue, string $guest, array $pills): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->mailStatus($this->inquiry(), $venue, $guest);

        $crawler = $this->client->request('GET', self::LIST);

        self::assertSame($pills, $crawler->filter('.crm-inbox__mails .pill')->each(static fn (Crawler $pill): string => $pill->attr('class').': '.$pill->text()));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function bothMails(): iterable
    {
        yield 'both sent' => ['sent', 'sent', ['pill pill--green: An die Location zugestellt', 'pill pill--green: Bestätigung an den Veranstalter zugestellt']];
        yield 'both pending' => ['pending', 'pending', ['pill pill--amber: Zustellung an die Location ausstehend', 'pill pill--amber: Bestätigung an den Veranstalter ausstehend']];
        yield 'failed and skipped' => ['failed', 'skipped', ['pill pill--red: Zustellung an die Location fehlgeschlagen', 'pill pill--grey: Bestätigung an den Veranstalter nicht gesendet']];
        yield 'guest copy failed' => ['sent', 'failed', ['pill pill--green: An die Location zugestellt', 'pill pill--red: Bestätigung an den Veranstalter fehlgeschlagen']];
    }

    public function test_a_get_leaves_the_status_alone(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $before = $this->connection->fetchAssociative('SELECT status, updated_at FROM crm_inquiry_links');

        $this->client->request('GET', self::LIST);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', self::LIST.'/'.$id);
        self::assertResponseIsSuccessful();

        self::assertSame('new', $before['status'] ?? null);
        self::assertSame($before, $this->connection->fetchAssociative('SELECT status, updated_at FROM crm_inquiry_links'));
        self::assertSame(0, $this->auditCount($id));
    }

    public function test_the_status_changes_and_comes_back_by_prg(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $back = self::LIST.'/'.$id.'?status=new';

        $this->changeStatus($id, ['status' => 'seen', 'return_to' => $back]);

        self::assertResponseRedirects($back, 303);
        self::assertSame('seen', $this->inquiryStatus($id));
        self::assertSame(1, $this->auditCount($id));
        self::assertStringContainsString('Status gespeichert.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_the_page_posts_its_two_forms(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        $crawler = $this->client->request('GET', self::LIST.'/'.$id.'?status=new');
        $done = $crawler->selectButton('Als erledigt markieren')->form();

        self::assertSame(self::LIST.'/'.$id.'/status', parse_url($done->getUri(), \PHP_URL_PATH));
        self::assertSame('done', $done->getValues()['status']);
        self::assertSame(self::LIST.'/'.$id.'?status=new', $done->getValues()['return_to']);

        $this->client->submit($done, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects(self::LIST.'/'.$id.'?status=new', 303);
        self::assertSame('done', $this->inquiryStatus($id));

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->selectButton('Als erledigt markieren'));

        $this->client->submit($crawler->selectButton('Speichern')->form(['status' => 'new']), [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame('new', $this->inquiryStatus($id));
    }

    public function test_the_same_status_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry(status: InquiryStatus::Seen);

        $this->changeStatus($id, ['status' => 'seen']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame(0, $this->auditCount($id));
        self::assertSelectorNotExists('.toast');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.toast');
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_post_changes_nothing(array $fields, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        $this->client->request('POST', self::LIST.'/'.$id.'/status', [...$fields, 'status' => 'done'], server: $server);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame('new', $this->inquiryStatus($id));
        self::assertSame(0, $this->auditCount($id));
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('badValues')]
    public function test_a_bad_value_changes_nothing(array $fields): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        $this->changeStatus($id, $fields);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame('new', $this->inquiryStatus($id));
        self::assertSame(0, $this->auditCount($id));
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast')->text());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badValues(): iterable
    {
        yield 'unknown' => [['status' => 'archived']];
        yield 'empty' => [['status' => '']];
        yield 'an array' => [['status' => ['done']]];
        yield 'missing' => [[]];
    }

    public function test_a_foreign_return_to_goes_to_the_list(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        $this->changeStatus($id, ['status' => 'seen', 'return_to' => 'https://evil.example/']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame('seen', $this->inquiryStatus($id));
    }

    public function test_an_unknown_id_goes_to_the_list_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = EntityId::generate();

        $this->changeStatus($id, ['status' => 'seen', 'return_to' => self::LIST.'/'.$id.'?status=new']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Anfrage gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_an_inquiry_gone_after_the_check_says_so_too(): void
    {
        $this->commandsFailWith(InquiryNotFound::forId('x'));
        $this->logInAs(AdminRole::Editor);
        $id = InquiryFactory::createOne()->id();
        $this->connection->executeStatement('INSERT INTO crm_inquiry_links (inquiry_id) VALUES (?)', [$id]);

        $this->changeStatus($id, ['status' => 'seen', 'return_to' => self::LIST.'/'.$id]);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Anfrage gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_failed_venue_mail_is_sent_again(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mailStatus($id, 'failed', 'skipped');
        $back = self::LIST.'/'.$id.'?status=new';

        $this->resend($id, ['return_to' => $back]);

        self::assertResponseRedirects($back, 303);
        self::assertEmailCount(2);
        self::assertSame(['sent', 'sent'], $this->mails($id));
        self::assertSame(0, $this->auditCount($id));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Die Anfrage ist an die Location gesendet.', $crawler->filter('.toast')->text());
        self::assertCount(0, $crawler->selectButton('Erneut senden'));
    }

    public function test_a_pending_venue_mail_is_sent_from_the_page(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mailStatus($id, 'pending', 'pending');

        $form = $this->client->request('GET', self::LIST.'/'.$id.'?status=new')->selectButton('Erneut senden')->form();
        self::assertSame(self::LIST.'/'.$id.'/senden', parse_url($form->getUri(), \PHP_URL_PATH));

        $this->client->submit($form, [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects(self::LIST.'/'.$id.'?status=new', 303);
        self::assertEmailCount(2);
        self::assertSame(['sent', 'sent'], $this->mails($id));
    }

    public function test_a_resend_that_fails_says_so_and_stays_failed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mailStatus($id, 'failed', 'skipped');
        $this->venueMailFails();

        $this->resend($id);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertSame(['failed', 'skipped'], $this->mails($id));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Die Anfrage konnte nicht gesendet werden.', $crawler->filter('.toast')->text());
    }

    public function test_a_sent_venue_mail_sends_nothing_and_has_no_button(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mailStatus($id, 'sent', 'failed');

        self::assertCount(0, $this->client->request('GET', self::LIST.'/'.$id)->selectButton('Erneut senden'));

        $this->resend($id);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertEmailCount(0);
        self::assertSame(['sent', 'failed'], $this->mails($id));
        self::assertStringContainsString('bereits an die Location zugestellt', $this->client->followRedirect()->filter('.toast')->text());
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    #[DataProvider('forgedPosts')]
    public function test_a_forged_resend_sends_nothing(array $fields, array $server): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mailStatus($id, 'failed', 'skipped');

        $this->client->request('POST', self::LIST.'/'.$id.'/senden', $fields, server: $server);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertEmailCount(0);
        self::assertSame(['failed', 'skipped'], $this->mails($id));
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_resend_of_an_unknown_id_goes_to_the_list_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = EntityId::generate();

        $this->resend($id, ['return_to' => self::LIST.'/'.$id.'?status=new']);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Anfrage gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_a_deleted_venue_shows_its_name_unlinked(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry(fields: ['venue' => VenueFactory::new()->withContact()->create(['name' => 'Alte Spinnerei'])]);
        $this->connection->executeStatement('UPDATE inquiries SET venue_id = NULL WHERE id = ?', [$id]);

        $title = $this->client->request('GET', self::LIST.'/'.$id)->filter('.crm-inbox__title');

        self::assertStringContainsString('Alte Spinnerei', $title->text());
        self::assertCount(0, $title->filter('a'));
    }

    public function test_it_refuses_anonymous_visitors(): void
    {
        $id = EntityId::generate();

        $this->client->request('GET', self::LIST);
        self::assertResponseRedirects('/admin/login');

        $this->client->request('GET', self::LIST.'/'.$id);
        self::assertResponseRedirects('/admin/login');

        $this->client->request('POST', self::LIST.'/'.$id.'/status', ['_token' => self::CSRF, 'status' => 'done'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects('/admin/login');
    }

    #[DataProvider('roles')]
    public function test_both_roles_reach_it(AdminRole $role): void
    {
        $this->logInAs($role);

        $this->client->request('GET', self::LIST);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Anfragen');
    }

    /**
     * @return iterable<string, array{AdminRole}>
     */
    public static function roles(): iterable
    {
        yield 'admin' => [AdminRole::Admin];
        yield 'editor' => [AdminRole::Editor];
    }

    public function test_a_brief_child_has_the_sammelanfrage_pill_and_a_single_inquiry_none(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->brief(['Schloss Seeblick', 'Alte Mühle'], '2026-10-02 10:00:00');
        $single = $this->inquiry('2026-10-01 10:00:00');

        $rows = $this->client->request('GET', self::LIST)->filter('.crm-inbox__row');

        self::assertCount(3, $rows);
        $pills = $rows->each(static fn (Crawler $row): array => [
            (string) $row->attr('href'),
            $row->filter('.pill')->each(static fn (Crawler $pill): string => $pill->text()),
        ]);
        foreach ($pills as [$href, $labels]) {
            self::assertSame(!str_contains($href, $single), \in_array('Sammelanfrage', $labels, true), $href);
        }
    }

    public function test_the_detail_of_a_brief_child_names_the_other_venues_with_links(): void
    {
        $this->logInAs(AdminRole::Editor);
        [$open, $second, $third] = $this->brief(['Schloss Seeblick', 'Zunfthaus', 'Alte Mühle'], '2026-10-02 10:00:00', venueCount: 3);

        $line = $this->client->request('GET', self::LIST.'/'.$open)->filter('#anfrage-detail .crm-inbox__brief');

        self::assertSame('Sammelanfrage Teil einer Sammelanfrage an 3 Locations: Alte Mühle, Zunfthaus', $line->text());
        self::assertSame('Sammelanfrage', $line->filter('.pill--blue')->text());
        self::assertSame(
            [
                ['Alte Mühle', self::LIST.'/'.$third.'?status=all#anfrage-detail'],
                ['Zunfthaus', self::LIST.'/'.$second.'?status=all#anfrage-detail'],
            ],
            $line->filter('a')->each(static fn (Crawler $link): array => [$link->text(), (string) $link->attr('href')]),
        );
    }

    public function test_a_sibling_the_inbox_has_not_ingested_is_named_without_a_link(): void
    {
        $this->logInAs(AdminRole::Editor);
        [$open, $other] = $this->brief(['Schloss Seeblick', 'Zunfthaus'], '2026-10-02 10:00:00');
        $this->connection->executeStatement('DELETE FROM crm_inquiry_links WHERE inquiry_id = ?', [$other]);

        $line = $this->client->request('GET', self::LIST.'/'.$open)->filter('#anfrage-detail .crm-inbox__brief');

        self::assertSame('Sammelanfrage Teil einer Sammelanfrage an 2 Locations: Zunfthaus', $line->text());
        self::assertCount(0, $line->filter('a'));
    }

    public function test_the_detail_of_a_single_inquiry_has_no_brief_line(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->brief(['Schloss Seeblick', 'Zunfthaus'], '2026-10-01 10:00:00');
        $single = $this->inquiry('2026-10-02 10:00:00');

        $this->client->request('GET', self::LIST.'/'.$single);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#anfrage-detail .crm-inbox__brief');
        self::assertSelectorTextNotContains('#anfrage-detail', 'Sammelanfrage');
    }

    /**
     * The detail reads the brief and its other children in its own statement (F13).
     */
    public function test_the_open_brief_child_stays_within_its_statements(): void
    {
        $this->logInAs(AdminRole::Editor);
        [$id] = $this->brief(['Schloss Seeblick', 'Zunfthaus', 'Alte Mühle'], '2026-10-01 10:00:00', venueCount: 3);

        self::assertSame(6, $this->queriesOf(self::LIST.'/'.$id));
        self::assertSame('3', $this->client->getCrawler()->filter('.sidebar__badge')->text());
    }

    /**
     * The counts, the rows, the inquiry, its feedback (G16) and the task badge: the inquiry badge reads the counts'
     * figure. One GET each, as a second one on this client also refreshes the user.
     */
    public function test_the_list_stays_within_its_statements(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->qualified($this->inquiry());
        $this->inquiry();

        self::assertSame(6, $this->queriesOf(self::LIST));
        self::assertSame('2', $this->client->getCrawler()->filter('.sidebar__badge')->text());
    }

    public function test_the_open_inquiry_stays_within_its_statements(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->qualified($id);
        $this->inquiry();

        self::assertSame(6, $this->queriesOf(self::LIST.'/'.$id));
        self::assertSame('2', $this->client->getCrawler()->filter('.sidebar__badge')->text());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function inquiry(string $createdAt = '2026-10-01 10:00:00', InquiryStatus $status = InquiryStatus::New, array $fields = []): string
    {
        $id = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable($createdAt.' UTC'), ...$fields])->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);

        return $id;
    }

    /**
     * A brief (F13) whose children are ingested like any inquiry, one per venue name, in the given order.
     *
     * @param list<string> $venueNames
     *
     * @return list<string>
     */
    private function brief(array $venueNames, string $createdAt, int $venueCount = 2): array
    {
        $brief = InquiryBriefFactory::createOne(['venueCount' => $venueCount]);

        return array_map(fn (string $name): string => $this->inquiry($createdAt, fields: [
            'brief' => $brief,
            'venue' => VenueFactory::new()->withContact()->create(['name' => $name]),
        ]), $venueNames);
    }

    /**
     * Qualified and marked (G7), so the ceilings hold with the pills of the qualification on the page.
     */
    private function qualified(string $id): void
    {
        $this->connection->executeStatement(
            "UPDATE inquiries SET qualification = 'unqualified', qualification_reason = 'duplicate', qualification_override = 'test' WHERE id = ?",
            [$id],
        );
    }

    private function mailStatus(string $id, string $venue, string $guest): void
    {
        $this->connection->executeStatement(
            'UPDATE inquiries SET venue_mail_status = ?, guest_mail_status = ? WHERE id = ?',
            [$venue, $guest, $id],
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function changeStatus(string $id, array $fields): void
    {
        $this->client->request('POST', self::LIST.'/'.$id.'/status', [...$fields, '_token' => self::CSRF], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /**
     * @param array<string, string> $fields
     */
    private function resend(string $id, array $fields = []): void
    {
        $this->client->request('POST', self::LIST.'/'.$id.'/senden', [...$fields, '_token' => self::CSRF], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /**
     * @return array{string, string}
     */
    private function mails(string $id): array
    {
        $row = $this->connection->fetchNumeric('SELECT venue_mail_status, guest_mail_status FROM inquiries WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return [(string) $row[0], (string) $row[1]];
    }

    /**
     * A transport's event inside the bus, where the handler records the failure (`SubmitInquiryMailErrorTest`).
     */
    private function venueMailFails(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            if (!$event->isQueued()) {
                throw new \RuntimeException('A mail error.');
            }
        });
    }

    private function inquiryStatus(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM crm_inquiry_links WHERE inquiry_id = ?', [$id]);
    }

    private function auditCount(string $id): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM admin_audit_log WHERE subject_type = 'inquiry' AND subject_id = ?", [$id]);
    }

    /**
     * @return array<string, string>
     */
    private function facts(Crawler $detail): array
    {
        $labels = $detail->filter('.crm-inbox__facts dt')->each(static fn (Crawler $label): string => $label->text());
        $values = $detail->filter('.crm-inbox__facts dd')->each(static fn (Crawler $value): string => $value->text());

        return array_combine($labels, $values);
    }
}
