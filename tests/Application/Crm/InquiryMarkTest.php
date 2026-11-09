<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\IngestInquiry;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryFactory;
use App\Inquiries\Exception\InquiryNotFound;
use App\Shared\Uid\EntityId;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The qualification in the inbox and the staff mark (D19, G7, D56): the pills, the spam / test forms with PRG, the
 * audit, and a marked `new` inquiry filed as `done`.
 */
final class InquiryMarkTest extends WebTestCase
{
    use AdminSession;
    use Factories;
    use FailsCommands;
    use ForgedPosts;

    private const string LIST = '/admin/crm/anfragen';
    private const string CSRF = 'csrf-token';

    public function test_the_detail_and_the_list_show_the_qualification(): void
    {
        $this->logInAs(AdminRole::Editor);
        $qualified = $this->inquiry('2026-10-01 10:00:00', ['venue_mail_status' => 'sent', 'qualification' => 'qualified']);
        $duplicate = $this->inquiry('2026-10-02 10:00:00', ['venue_mail_status' => 'sent', 'qualification' => 'unqualified', 'qualification_reason' => 'duplicate']);

        self::assertSame(['pill pill--green: Qualifiziert'], $this->qualificationPills($this->client->request('GET', self::LIST.'/'.$qualified)));
        $crawler = $this->client->request('GET', self::LIST.'/'.$duplicate);
        self::assertSame(['pill pill--grey: Nicht qualifiziert: Doppelte Anfrage'], $this->qualificationPills($crawler));
        self::assertSame(
            ['pill pill--grey: Nicht qualifiziert: Doppelte Anfrage', 'pill pill--green: Qualifiziert'],
            $crawler->filter('.crm-inbox__row .pill')->each(static fn (Crawler $pill): string => $pill->attr('class').': '.$pill->text()),
        );
    }

    public function test_an_inquiry_not_yet_delivered_shows_no_qualification(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        self::assertSame([], $this->qualificationPills($this->client->request('GET', self::LIST.'/'.$id)));
    }

    public function test_marking_spam_files_it_as_done_and_comes_back_by_prg(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry(fields: ['venue_mail_status' => 'sent', 'qualification' => 'qualified']);
        $back = self::LIST.'/'.$id.'?status=new';

        $crawler = $this->client->request('GET', $back);
        $form = $crawler->selectButton('Als Spam markieren')->form();
        self::assertSame(self::LIST.'/'.$id.'/markierung', parse_url($form->getUri(), \PHP_URL_PATH));
        self::assertSame(['_token', 'override', 'return_to'], array_keys($form->getValues()));
        self::assertSame('spam', $form->getValues()['override']);
        self::assertCount(1, $crawler->selectButton('Als Test markieren'));
        self::assertCount(0, $crawler->selectButton('Markierung entfernen'));

        $this->mark($id, ['override' => 'spam', 'return_to' => $back]);

        self::assertResponseRedirects($back, 303);
        self::assertSame('spam', $this->override($id));
        self::assertSame('done', $this->inquiryStatus($id));
        self::assertSame([['override' => 'spam'], ['status' => 'done']], $this->auditChanges($id));
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Markierung gespeichert.', $crawler->filter('.toast')->text());
        self::assertSame(['pill pill--red: Spam', 'pill pill--green: Qualifiziert'], $this->qualificationPills($crawler));
        self::assertCount(1, $crawler->selectButton('Markierung entfernen'));
        self::assertCount(0, $crawler->selectButton('Als Spam markieren'));

        $crawler = $this->client->request('GET', self::LIST.'?status=done');
        self::assertSame(['pill pill--red: Spam'], $crawler->filter('.crm-inbox__row .pill')->each(static fn (Crawler $pill): string => $pill->attr('class').': '.$pill->text()));
    }

    public function test_unmarking_removes_the_mark_and_keeps_the_status(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mark($id, ['override' => 'test']);
        $this->client->followRedirect();

        $this->mark($id, ['override' => '', 'return_to' => self::LIST.'/'.$id]);

        self::assertResponseRedirects(self::LIST.'/'.$id, 303);
        self::assertNull($this->override($id));
        self::assertSame('done', $this->inquiryStatus($id));
        self::assertStringContainsString('Markierung entfernt.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_marking_a_seen_inquiry_leaves_its_status(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry(status: InquiryStatus::Seen);

        $this->mark($id, ['override' => 'test']);

        self::assertSame('test', $this->override($id));
        self::assertSame('seen', $this->inquiryStatus($id));
        self::assertSame([['override' => 'test']], $this->auditChanges($id));
    }

    public function test_the_same_mark_sends_no_command(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();
        $this->mark($id, ['override' => 'spam']);

        $this->mark($id, ['override' => 'spam']);
        $this->mark($this->inquiry('2026-10-02 10:00:00'), ['override' => '']);

        self::assertCount(2, $this->auditChanges($id));
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

        $this->client->request('POST', self::LIST.'/'.$id.'/markierung', [...$fields, 'override' => 'spam'], server: $server);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->override($id));
        self::assertSame([], $this->auditChanges($id));
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('badValues')]
    public function test_a_bad_value_changes_nothing(array $fields): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry();

        $this->mark($id, $fields);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertNull($this->override($id));
        self::assertSame([], $this->auditChanges($id));
        self::assertStringContainsString('Das hat nicht geklappt.', $this->client->followRedirect()->filter('.toast')->text());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badValues(): iterable
    {
        yield 'unknown' => [['override' => 'fraud']];
        yield 'an array' => [['override' => ['spam']]];
        yield 'missing' => [[]];
    }

    public function test_an_unknown_id_goes_to_the_list_and_says_so(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = EntityId::generate();

        $this->mark($id, ['override' => 'spam', 'return_to' => self::LIST.'/'.$id]);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Anfrage gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_an_inquiry_gone_after_the_check_says_so_too(): void
    {
        $this->commandsFailWith(InquiryNotFound::forId('x'));
        $this->logInAs(AdminRole::Editor);
        $id = InquiryFactory::createOne()->id();
        $this->connection->executeStatement('INSERT INTO crm_inquiry_links (inquiry_id) VALUES (?)', [$id]);

        $this->mark($id, ['override' => 'spam', 'return_to' => self::LIST.'/'.$id]);

        self::assertResponseRedirects(self::LIST, 303);
        self::assertStringContainsString('Diese Anfrage gibt es nicht mehr.', $this->client->followRedirect()->filter('.toast')->text());
    }

    public function test_it_refuses_anonymous_visitors(): void
    {
        $this->client->request('POST', self::LIST.'/'.EntityId::generate().'/markierung', ['_token' => self::CSRF, 'override' => 'spam'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/admin/login');
    }

    /**
     * @param array<string, mixed> $fields columns of `inquiries` to set
     */
    private function inquiry(string $createdAt = '2026-10-01 10:00:00', array $fields = [], InquiryStatus $status = InquiryStatus::New): string
    {
        $id = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable($createdAt.' UTC')])->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);
        if ([] !== $fields) {
            $set = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($fields)));
            $this->connection->executeStatement('UPDATE inquiries SET '.$set.' WHERE id = ?', [...array_values($fields), $id]);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function mark(string $id, array $fields): void
    {
        $this->client->request('POST', self::LIST.'/'.$id.'/markierung', [...$fields, '_token' => self::CSRF], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
    }

    /**
     * @return list<string>
     */
    private function qualificationPills(Crawler $crawler): array
    {
        return $crawler->filter('.crm-inbox__detail .crm-inbox__block')->reduce(
            static fn (Crawler $block): bool => 'Qualifizierung' === $block->filter('h3')->text(),
        )->filter('.pill')->each(static fn (Crawler $pill): string => $pill->attr('class').': '.$pill->text());
    }

    private function override(string $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT qualification_override FROM inquiries WHERE id = ?', [$id]);

        return null === $value ? null : (string) $value;
    }

    private function inquiryStatus(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM crm_inquiry_links WHERE inquiry_id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditChanges(string $id): array
    {
        return array_map(
            static fn (mixed $changes): array => (array) json_decode((string) $changes, true, flags: \JSON_THROW_ON_ERROR),
            $this->connection->fetchFirstColumn("SELECT changes FROM admin_audit_log WHERE subject_type = 'inquiry' AND subject_id = ? ORDER BY created_at, id", [$id]),
        );
    }
}
