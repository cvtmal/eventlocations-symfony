<?php

declare(strict_types=1);

namespace App\Tests\Application\Crm;

use App\Crm\Entity\Company;
use App\Crm\OptOutToken;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The opt-out link of a CRM mail (G11): a GET only asks, a POST (the page's button or RFC 8058's one-click) puts the
 * Location and every address it holds on the suppression list for good, once; a wrong token is a private 404.
 */
final class OptOutPageTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        parent::tearDown();
    }

    public function test_a_get_asks_and_writes_nothing(): void
    {
        $company = $this->company();

        $crawler = $this->client->request('GET', $this->url($company));

        self::assertResponseIsSuccessful();
        self::assertSame('Keine Mails und Briefe mehr von uns?', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('form[method="post"][action="'.$this->url($company).'"] button[type="submit"]'));
        self::assertSame(0, $this->rows('suppressions'));
        $this->assertPrivate();
    }

    public function test_the_button_suppresses_the_location_and_its_addresses_once(): void
    {
        $company = $this->company();

        $crawler = $this->client->request('POST', $this->url($company));

        self::assertResponseIsSuccessful();
        self::assertSame('Du bist abgemeldet', trim($crawler->filter('h1')->text()));
        $this->assertPrivate();
        self::assertSame(
            [[null, $company->id(), 'opt_out'], ['info@remise.ch', null, 'opt_out'], ['mia@remise.ch', null, 'opt_out']],
            array_map(array_values(...), $this->connection()->fetchAllAssociative('SELECT email, company_id, reason FROM suppressions ORDER BY email NULLS FIRST')),
        );
        self::assertSame('{"suppression": "opt_out"}', $this->connection()->fetchOne("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'company' AND actor_type = 'system'"));

        // RFC 8058's one-click POST and a reload change nothing and write no second audit row.
        $this->client->request('POST', $this->url($company), ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', $this->url($company));
        self::assertSame('Du bist abgemeldet', trim($crawler->filter('h1')->text()));
        self::assertSame(3, $this->rows('suppressions'));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'company'"));
    }

    public function test_a_location_suppressed_through_one_address_still_gets_its_own_row(): void
    {
        $company = $this->company();
        $this->connection()->insert('suppressions', ['id' => \App\Shared\Uid\EntityId::generate(), 'email' => 'mia@remise.ch', 'reason' => 'objection']);

        $crawler = $this->client->request('GET', $this->url($company));
        self::assertSame('Keine Mails und Briefe mehr von uns?', trim($crawler->filter('h1')->text()));
        $this->client->request('POST', $this->url($company));

        self::assertResponseIsSuccessful();
        self::assertSame('opt_out', $this->connection()->fetchOne('SELECT reason FROM suppressions WHERE company_id = ?', [$company->id()]));
    }

    public function test_a_wrong_or_foreign_token_is_a_private_404_and_writes_nothing(): void
    {
        $company = $this->company();
        $preview = self::getContainer()->get(\App\Venues\PreviewToken::class);
        self::assertInstanceOf(\App\Venues\PreviewToken::class, $preview);

        foreach (['/abmelden/'.str_repeat('A', 44), '/abmelden/kurz', '/abmelden/'.$preview->forId($company->id())] as $url) {
            $this->client->request('POST', $url);
            self::assertResponseStatusCodeSame(404, $url);
            $this->assertPrivate();
        }
        $this->client->request('PUT', $this->url($company));
        self::assertResponseStatusCodeSame(405);
        self::assertSame(0, $this->rows('suppressions'));
    }

    private function company(): Company
    {
        $company = CompanyFactory::new()->afterInstantiate(static fn (Company $company) => $company->changeEmail('info@remise.ch'))->create(['name' => 'Remise am Bach']);
        ContactFactory::createOne(['company' => $company, 'email' => 'mia@remise.ch']);
        ContactFactory::createOne(['company' => $company, 'email' => null]);

        return $company;
    }

    private function url(Company $company): string
    {
        $tokens = self::getContainer()->get(OptOutToken::class);
        self::assertInstanceOf(OptOutToken::class, $tokens);

        return '/abmelden/'.$tokens->forCompany($company->id());
    }

    private function assertPrivate(): void
    {
        $response = $this->client->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame([], $response->headers->getCookies());
        self::assertStringNotContainsString('data-stats-url', (string) $response->getContent());
        self::assertStringNotContainsString('rel="canonical"', (string) $response->getContent());
    }

    private function rows(string $table): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
