<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Admin\ValueObject\AdminRole;
use App\Crm\Command\IngestInquiry;
use App\Crm\ValueObject\InquiryStatus;
use App\Factory\CompanyFactory;
use App\Factory\InquiryFactory;
use App\Tests\Support\Admin\AdminSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Anfragen" in the sidebar (N5): the link, its active state, and the badge on the three kinds of page: its own statement
 * on the dashboard, the owners column on the company list, the tabs in the inbox.
 */
final class InquiriesNavigationTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    private const string INBOX = '/admin/crm/anfragen';
    private const string ITEM = '.sidebar__item[href="'.self::INBOX.'"]';

    public function test_the_item_links_to_the_inbox_and_is_active_there(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', self::INBOX);

        $active = $crawler->filter('.sidebar__item[aria-current="page"]');
        self::assertSame('Anfragen', $active->filter('.sidebar__label')->text());
        self::assertSame(self::INBOX, $active->attr('href'));
        self::assertCount(1, $active->filter('use[href$="#inbox"]'));
    }

    public function test_it_stays_active_on_an_inquiry(): void
    {
        $this->logInAs(AdminRole::Editor);
        $id = $this->inquiry(InquiryStatus::Seen);

        $crawler = $this->client->request('GET', self::INBOX.'/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSame('Anfragen', $crawler->filter('.sidebar__item[aria-current="page"] .sidebar__label')->text());
    }

    public function test_it_is_not_active_elsewhere(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin');

        self::assertSame('Anfragen', $crawler->filter(self::ITEM.' .sidebar__label')->text());
        self::assertNull($crawler->filter(self::ITEM)->attr('aria-current'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'dashboard, its own statement' => ['/admin'];
        yield 'company list, the owners column' => ['/admin/crm/firmen'];
        yield 'inbox, the tabs' => [self::INBOX];
    }

    #[DataProvider('pages')]
    public function test_the_badge_counts_the_new_inquiries_alone(string $page): void
    {
        $this->logInAs(AdminRole::Editor);
        CompanyFactory::createOne();
        $this->inquiry();
        $this->inquiry();
        $this->inquiry(InquiryStatus::Seen);
        $this->inquiry(InquiryStatus::Done);
        InquiryFactory::createOne();

        $this->client->request('GET', $page);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame(self::ITEM.' .sidebar__badge', '2');
    }

    #[DataProvider('pages')]
    public function test_at_nought_there_is_no_badge(string $page): void
    {
        $this->logInAs(AdminRole::Editor);
        CompanyFactory::createOne();
        $this->inquiry(InquiryStatus::Seen);
        $this->inquiry(InquiryStatus::Done);
        InquiryFactory::createOne();

        $this->client->request('GET', $page);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::ITEM.' .sidebar__badge');
    }

    private function inquiry(InquiryStatus $status = InquiryStatus::New): string
    {
        $id = InquiryFactory::createOne()->id();
        $this->dispatch(new IngestInquiry($id));
        $this->connection->executeStatement('UPDATE crm_inquiry_links SET status = ? WHERE inquiry_id = ?', [$status->value, $id]);

        return $id;
    }
}
