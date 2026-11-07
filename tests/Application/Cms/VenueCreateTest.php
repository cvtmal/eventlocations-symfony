<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

final class VenueCreateTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_anonymous_is_sent_to_the_login(): void
    {
        $this->client->request('GET', '/admin/locations/neu');

        self::assertResponseRedirects('/admin/login');
    }

    public function test_a_new_venue_starts_unpublished_in_the_editor(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/locations/neu');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Erfassen', [
            'create_venue[name]' => 'Neue Scheune',
            'create_venue[tier]' => 'pro',
            'create_venue[capacityMax]' => '140',
        ]);

        $row = $this->connection->fetchAssociative("SELECT v.number, v.status, tr.code AS tier, v.capacity_max, t.slug FROM venues v JOIN tiers tr ON tr.id = v.tier_id LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de' WHERE v.name = 'Neue Scheune'");
        self::assertIsArray($row);
        self::assertSame(['unpublished', 'pro', 140], [$row['status'], $row['tier'], $row['capacity_max']]);
        self::assertResponseRedirects('/admin/locations/'.$row['number']);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('«Neue Scheune» ist erfasst.', $crawler->filter('.toast')->text());
        self::assertSame('Neue Scheune', $crawler->filter('.record-header h1')->text());
        self::assertStringContainsString('Unpubliziert', $crawler->filter('.record-header')->text());
        self::assertCount(0, $crawler->filter('.venue-checklist__item--done'));
        self::assertStringContainsString('Andrea Keller hat den Eintrag erfasst', $crawler->filter('section[aria-labelledby="venue-history"]')->text());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $row['number']]));

        if (\is_string($row['slug'])) {
            $this->client->request('GET', '/eventlokal/'.$row['slug']);
            self::assertResponseStatusCodeSame(410);
        }
    }

    /**
     * F12: the choices are the rows of `tiers` (name as label, best first), and a new venue starts on Free.
     */
    public function test_the_form_offers_the_plans_of_the_database_and_preselects_free(): void
    {
        $this->logInAs(AdminRole::Editor);

        $crawler = $this->client->request('GET', '/admin/locations/neu');

        self::assertResponseIsSuccessful();
        $options = $crawler->filter('select#create_venue_tier option');
        self::assertSame(['pro', 'free'], $options->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame(['Pro', 'Free'], $options->each(static fn (Crawler $option): string => $option->text()));
        self::assertSame('free', $crawler->filter('select#create_venue_tier option[selected]')->attr('value'));
    }

    public function test_a_new_venue_without_a_chosen_plan_is_on_free(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/locations/neu');
        $this->client->submitForm('Erfassen', ['create_venue[name]' => 'Kleine Scheune', 'create_venue[capacityMax]' => '60']);

        self::assertSame('free', $this->connection->fetchOne("SELECT tr.code FROM venues v JOIN tiers tr ON tr.id = v.tier_id WHERE v.name = 'Kleine Scheune'"));
    }

    public function test_a_name_without_letters_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/locations/neu');
        $crawler = $this->client->submitForm('Erfassen', [
            'create_venue[name]' => '!!!',
            'create_venue[capacityMax]' => '140',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#create_venue_name')->attr('aria-invalid'));
        self::assertSame('!!!', $crawler->filter('#create_venue_name')->attr('value'));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM venues WHERE name = '!!!'"));
    }

    public function test_missing_values_are_field_errors(): void
    {
        $this->logInAs(AdminRole::Editor);

        $this->client->request('GET', '/admin/locations/neu');
        $crawler = $this->client->submitForm('Erfassen', ['create_venue[name]' => '', 'create_venue[capacityMax]' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $crawler->filter('#create_venue_name')->attr('aria-invalid'));
        self::assertSame('true', $crawler->filter('#create_venue_capacityMax')->attr('aria-invalid'));
    }
}
