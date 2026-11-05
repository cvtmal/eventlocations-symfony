<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\IngestInquiry;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\InquiryFactory;
use App\Factory\VenueFactory;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Neue Anfragen" on the dashboard (CRM-9, `Main.dc.html`): the five newest, whatever their status.
 */
final class NewInquiriesWidgetTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_a_row_shows_the_couple_date_venue_guests_and_status(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry('2026-10-30 23:30:00', fields: [
            'firstName' => 'Sina',
            'lastName' => 'Huber',
            'venue' => VenueFactory::new()->withContact()->create(['name' => 'Schloss Seeblick']),
            'guestCount' => 1200,
        ]);

        $widget = $this->widget();

        self::assertSame('Neue Anfragen', $widget->filter('h2')->text());
        self::assertSame('Neue Anfragen, Tabelle', $widget->filter('[role="region"]')->attr('aria-label'), 'Two regions with one name fail axe\'s landmark-unique.');
        self::assertSame('Alle ansehen', $widget->filter('.widget__head-link a')->text());
        self::assertSame('/admin/crm/anfragen', $widget->filter('.widget__head-link a')->attr('href'));
        self::assertSame(['Paar', 'Eintrag', 'Gäste', 'Status'], $widget->filter('thead th')->each(static fn (Crawler $head): string => $head->text()));
        $cells = $widget->filter('tbody td');
        self::assertSame('Sina Huber', $cells->eq(0)->filter('strong')->text());
        self::assertSame('31.10.2026', $cells->eq(0)->filter('.crm-list__detail')->text(), 'The date is Zurich\'s, and absolute.');
        self::assertSame('Schloss Seeblick', $cells->eq(1)->text());
        self::assertSame('1\'200', $cells->eq(2)->text());
        self::assertSame('Neu', $cells->eq(3)->filter('.pill--pink')->text());
    }

    public function test_it_shows_the_five_newest_whatever_their_status(): void
    {
        $this->logInAs(AdminRole::Editor);
        $this->inquiry('2026-10-01 10:00:00', fields: ['firstName' => 'Sechs', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-06 10:00:00', fields: ['firstName' => 'Eins', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-05 10:00:00', InquiryStatus::Done, ['firstName' => 'Zwei', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-04 10:00:00', InquiryStatus::Seen, ['firstName' => 'Drei', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-03 10:00:00', fields: ['firstName' => 'Vier', 'lastName' => 'Paar']);
        $this->inquiry('2026-10-02 10:00:00', fields: ['firstName' => 'Fünf', 'lastName' => 'Paar']);

        $widget = $this->widget();

        self::assertSame(['Eins Paar', 'Zwei Paar', 'Drei Paar', 'Vier Paar', 'Fünf Paar'], $widget->filter('tbody strong')->each(static fn (Crawler $name): string => $name->text()));
        self::assertSame('Erledigt', $widget->filter('tbody tr')->eq(1)->filter('.pill--green')->text());
        self::assertSame('Gesehen', $widget->filter('tbody tr')->eq(2)->filter('.pill--blue')->text());
    }

    public function test_an_inquiry_not_yet_ingested_stays_out(): void
    {
        $this->logInAs(AdminRole::Editor);
        InquiryFactory::createOne();

        self::assertCount(0, $this->widget()->filter('tbody tr'));
    }

    public function test_with_no_inquiries_it_says_so_and_keeps_the_link(): void
    {
        $this->logInAs(AdminRole::Editor);

        $widget = $this->widget();

        self::assertSame('Noch keine Anfragen.', $widget->filter('.card__body')->text());
        self::assertCount(0, $widget->filter('table'));
        self::assertSame('/admin/crm/anfragen', $widget->filter('.widget__head-link a')->attr('href'));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function inquiry(string $createdAt, InquiryStatus $status = InquiryStatus::New, array $fields = []): void
    {
        $id = InquiryFactory::createOne(['createdAt' => new \DateTimeImmutable($createdAt.' UTC'), ...$fields])->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);
    }

    private function widget(): Crawler
    {
        return $this->client->request('GET', '/admin')->filter('.widget')->eq(1);
    }
}
