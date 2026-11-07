<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Tests\Support\Admin\AdminSession;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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
            'create_venue[tier]' => 'premium',
            'create_venue[capacityMax]' => '140',
        ]);

        $row = $this->connection->fetchAssociative("SELECT v.number, v.status, v.tier, v.capacity_max, t.slug FROM venues v LEFT JOIN venue_translations t ON t.venue_id = v.id AND t.locale = 'de' WHERE v.name = 'Neue Scheune'");
        self::assertIsArray($row);
        self::assertSame(['unpublished', 'premium', 140], [$row['status'], $row['tier'], $row['capacity_max']]);
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
