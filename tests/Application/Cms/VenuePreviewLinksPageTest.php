<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * G5: staff issue a preview link in the entry's Vorschau-Links tab, take its address and QR code, and
 * revoke it; the public link follows at once.
 */
final class VenuePreviewLinksPageTest extends WebTestCase
{
    use AdminSession;
    use Factories;

    public function test_staff_issue_a_link_that_opens_and_revoke_it_to_a_410(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::draft();
        $crawler = $this->client->request('GET', self::url($venue));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('a.tab--active', 'Vorschau-Links');
        self::assertSelectorTextContains('main', 'Noch kein Vorschau-Link.');

        $this->client->submit($crawler->filter('form[name="preview_link"]')->form(), [
            'preview_link[campaignCode]' => ' brief-2026-11 ',
            'preview_link[lifetimeDays]' => '30',
        ]);

        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Vorschau-Link erstellt.', $crawler->filter('.toast')->text());
        $row = $crawler->filter('table.preview-links tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString('brief-2026-11', $row->text());
        self::assertStringContainsString('Aktiv', $row->text());
        $link = (string) $row->filter('input.preview-links__url')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/vorschau/[A-Za-z0-9_-]{44}$#', $link);
        $qr = (string) $row->filter('img.preview-links__qr')->attr('src');

        $this->client->request('GET', $qr);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.preview-banner__label', 'Noch nicht vom Betreiber bestätigt');

        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter('dialog[id^="preview-revoke-"] form[method="post"]')->form());
        self::assertResponseStatusCodeSame(303);
        $crawler = $this->client->followRedirect();
        self::assertSame('Vorschau-Link zurückgezogen.', $crawler->filter('.toast')->text());
        self::assertStringContainsString('Zurückgezogen', $crawler->filter('table.preview-links tbody tr')->text());
        self::assertCount(0, $crawler->filter('input.preview-links__url'));

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(410);
        $this->client->request('GET', $qr);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            ['IssueVenuePreview', 'RevokeVenuePreview'],
            array_map(static fn (string $command): string => substr($command, (int) strrpos($command, '\\') + 1), $this->connection->fetchFirstColumn("SELECT command FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ? ORDER BY created_at, id", [(string) $venue->number()])),
        );
    }

    public function test_a_link_for_an_e_mail_has_no_campaign_and_the_qr_code_encodes_the_short_link(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::draft();
        $crawler = $this->client->request('GET', self::url($venue));

        $this->client->submit($crawler->filter('form[name="preview_link"]')->form(), ['preview_link[campaignCode]' => '']);

        self::assertResponseStatusCodeSame(303);
        $row = $this->client->followRedirect()->filter('table.preview-links tbody tr');
        self::assertStringContainsString('Ohne Kampagne (E-Mail)', $row->text());
        self::assertNull($this->connection->fetchOne('SELECT campaign_code FROM venue_previews WHERE venue_id = ?', [$venue->id()]));
        $short = (string) $row->filter('input.preview-links__short')->attr('value');
        self::assertMatchesRegularExpression('#^http://localhost/v/[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{10}$#', $short);
        self::assertEquals(['campaign' => null, 'days' => 90], json_decode((string) $this->connection->fetchOne("SELECT changes::text FROM admin_audit_log WHERE subject_type = 'venue' AND subject_id = ?", [(string) $venue->number()]), true));

        $this->client->request('GET', (string) $row->filter('img.preview-links__qr')->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('filename="vorschau-'.$venue->number().'-email.svg"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function test_a_campaign_that_is_no_plain_code_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::draft();
        $crawler = $this->client->request('GET', self::url($venue));

        $this->client->submit($crawler->filter('form[name="preview_link"]')->form(), ['preview_link[campaignCode]' => 'jemand@example.ch']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="preview_link"]', 'Nur Buchstaben, Ziffern');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_previews WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_revoke_without_its_token_changes_nothing(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::draft();
        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter('form[name="preview_link"]')->form(), ['preview_link[campaignCode]' => 'brief']);
        $id = (string) $this->connection->fetchOne('SELECT id FROM venue_previews WHERE venue_id = ?', [$venue->id()]);

        $this->client->request('POST', self::url($venue).'/'.$id.'/widerrufen', ['_token' => 'falsch']);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->connection->fetchOne('SELECT revoked_at FROM venue_previews WHERE id = ?', [$id]));
    }

    public function test_another_entrys_link_is_not_found(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::draft();
        $crawler = $this->client->request('GET', self::url($venue));
        $this->client->submit($crawler->filter('form[name="preview_link"]')->form(), ['preview_link[campaignCode]' => 'brief']);
        $id = (string) $this->connection->fetchOne('SELECT id FROM venue_previews WHERE venue_id = ?', [$venue->id()]);
        $other = self::draft('Zweite Remise', 'zweite-remise');

        $this->client->request('GET', self::url($other).'/'.$id.'/qr.svg');
        self::assertResponseStatusCodeSame(404);
    }

    private static function draft(string $name = 'Remise Vorschau', string $slug = 'remise-vorschau'): Venue
    {
        $venue = VenueFactory::new()->withoutLocation(null)->create(['name' => $name, 'status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => $slug, 'summary' => 'Eine vorbereitete Remise.']);

        return $venue;
    }

    private static function url(Venue $venue): string
    {
        return '/admin/locations/'.$venue->number().'/vorschau-links';
    }
}
