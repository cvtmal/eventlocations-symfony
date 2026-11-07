<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PhotoCandidateFactory;
use App\Factory\PortalUserFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SourceRecordFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePreviewFactory;
use App\Factory\VenueTranslationFactory;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\PreviewToken;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\ImageCandidate;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * G6 end to end: the preview's "Gratis übernehmen" posts its token into the claim flow, a code proves the address,
 * and an address on the website's domain manages the entry at once while anything else waits for staff.
 */
final class ClaimFlowTest extends WebTestCase
{
    use Factories;
    use PortalSession;

    private const string DOMAIN = 'remise-am-bach.example';
    private const string PASSWORD = 'long-enough-password';

    public function test_a_domain_address_claims_the_entry_and_the_preview_link_ends(): void
    {
        $venue = $this->venue();
        $token = $this->previewToken($venue);

        $this->claim($token, 'info@'.self::DOMAIN);
        $code = $this->mailedCode('info@'.self::DOMAIN);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main, body', 'info@'.self::DOMAIN);

        $this->client->submitForm('Bestätigen', ['claim_code[code]' => substr($code, 0, 3).' '.substr($code, 3)]);
        self::assertResponseRedirects('/konto/uebernehmen/passwort', 303);
        $this->client->followRedirect();
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);

        self::assertResponseRedirects('/konto/locations/'.$venue->number().'/website-fotos', 303);
        $this->client->followRedirect();
        self::assertResponseRedirects('/konto/locations/'.$venue->number().'/einrichten/kontakt', 302, 'no candidates: on to the contact step');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertSame('active', $this->connection->fetchOne('SELECT a.status FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id WHERE u.email = ?', ['info@'.self::DOMAIN]));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT count(*) FROM funnel_events WHERE venue_id = ? AND event IN ('claim_started', 'claim_verified')", [$venue->id()]));
        $this->client->request('GET', '/vorschau/'.$token);
        self::assertResponseStatusCodeSame(410);
    }

    public function test_no_url_of_the_flow_carries_the_token_and_every_page_is_private(): void
    {
        $token = $this->previewToken($this->venue());

        $this->client->request('POST', '/konto/uebernehmen/start', ['token' => $token, 'intent' => 'correct']);
        self::assertResponseRedirects('/konto/uebernehmen', 303);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.auth__form', 'Angaben korrigieren', 'came from "Angaben korrigieren"');
        $response = $this->client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertStringNotContainsString($token, (string) $response->getContent());
        self::assertStringNotContainsString('data-stats-page', (string) $response->getContent());
    }

    public function test_a_cross_site_post_cannot_start_a_claim(): void
    {
        $this->client->request('POST', '/konto/uebernehmen/start', ['token' => $this->previewToken($this->venue())], server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_password_step_needs_a_proved_code(): void
    {
        $this->claim($this->previewToken($this->venue()), 'info@'.self::DOMAIN);

        $this->client->request('GET', '/konto/uebernehmen/passwort');

        self::assertResponseStatusCodeSame(410);
    }

    public function test_a_bad_or_closed_link_and_a_flow_without_start_answer_410(): void
    {
        $venue = $this->venue();
        $revoked = VenuePreviewFactory::new()->revoked()->create(['venue' => $venue]);

        foreach (['kaputt', $this->tokens()->forId($revoked->id())] as $token) {
            $this->client->request('POST', '/konto/uebernehmen/start', ['token' => $token]);
            self::assertResponseStatusCodeSame(410);
        }
        foreach (['/konto/uebernehmen', '/konto/uebernehmen/code', '/konto/uebernehmen/passwort'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(410, $path);
        }
    }

    public function test_known_and_unknown_addresses_get_the_same_answer(): void
    {
        $venue = $this->venue();
        PortalUserFactory::createOne(['email' => 'bekannt@'.self::DOMAIN]);
        $pages = [];
        foreach (['bekannt@'.self::DOMAIN, 'neu@'.self::DOMAIN] as $email) {
            $this->claim($this->previewToken($venue), $email);
            self::assertResponseRedirects('/konto/uebernehmen/code', 303);
            $mail = self::getMailerMessage();
            self::assertInstanceOf(Email::class, $mail);
            self::assertSame('Dein Code für Remise am Bach', $mail->getSubject());
            $this->client->followRedirect();
            $pages[] = str_replace($email, '', (string) $this->client->getCrawler()->filter('.auth__form')->text());
        }

        self::assertSame($pages[0], $pages[1]);
    }

    public function test_a_wrong_code_is_counted_and_a_mail_provider_address_waits_for_staff(): void
    {
        $venue = $this->venue();
        $this->claim($this->previewToken($venue), 'remise@gmail.com');
        $code = $this->mailedCode('remise@gmail.com');
        $this->client->followRedirect();

        $this->client->submitForm('Bestätigen', ['claim_code[code]' => '000000' === $code ? '111111' : '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.auth__form', 'noch 4 Versuche');

        $this->client->submitForm('Bestätigen', ['claim_code[code]' => $code]);
        self::assertResponseRedirects('/konto/uebernehmen/passwort', 303);
        self::assertEmailCount(1, message: 'staff hear of the claim');
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail(), 'To', 'hallo@deine-eventlocation.ch');
        $this->client->followRedirect();
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/konto', 303);

        $this->client->request('GET', '/konto/locations/'.$venue->number());
        self::assertResponseStatusCodeSame(403);
        self::assertSame('pending', $this->connection->fetchOne('SELECT a.status FROM portal_venue_access a JOIN portal_users u ON u.id = a.portal_user_id WHERE u.email = ?', ['remise@gmail.com']));
    }

    public function test_the_ip_budget_answers_429_whatever_the_address(): void
    {
        $venue = $this->venue();
        for ($i = 0; $i < 5; ++$i) {
            $this->claim($this->previewToken($venue), 'p'.$i.'@'.self::DOMAIN);
            self::assertResponseRedirects('/konto/uebernehmen/code', 303);
        }

        $this->claim($this->previewToken($venue), 'p9@'.self::DOMAIN);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($this->client->getResponse()->headers->has('Retry-After'));
        self::assertEmailCount(0);
    }

    public function test_the_photo_step_lists_the_candidates_as_links_and_needs_the_declaration_for_a_tick(): void
    {
        $venue = $this->venue();
        $record = SourceRecordFactory::createOne(['venue' => $venue, 'url' => 'https://www.'.self::DOMAIN.'/', 'report' => ['final_url' => 'https://www.'.self::DOMAIN.'/']]);
        $saal = PhotoCandidateFactory::createOne(['sourceRecord' => $record, 'position' => 1, 'image' => new ImageCandidate('https://www.'.self::DOMAIN.'/bilder/saal.jpg', 'https://www.'.self::DOMAIN.'/', CandidateOrigin::Img, 'Der Saal', null, 1600, 900)]);
        $bach = PhotoCandidateFactory::createOne(['sourceRecord' => $record, 'position' => 2, 'image' => new ImageCandidate('https://www.'.self::DOMAIN.'/bilder/bach.jpg', 'https://www.'.self::DOMAIN.'/', CandidateOrigin::Img)]);
        $manager = $this->logInAsManager();
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        $crawler = $this->client->request('GET', '/konto/locations/'.$venue->number().'/website-fotos');

        self::assertResponseIsSuccessful();
        self::assertSame(['saal.jpg', 'bach.jpg'], $crawler->filter('.claim-photos__item label')->each(static fn ($label): string => explode(' ', trim($label->text()))[0]));
        $link = $crawler->filter('.claim-photos__item a')->first();
        self::assertSame('https://www.'.self::DOMAIN.'/bilder/saal.jpg', $link->attr('href'));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
        self::assertSelectorNotExists('img[src^="https://www.'.self::DOMAIN.'"]');

        $this->client->submitForm('Weiter', ['claim_photos[photos]' => [$saal->id()]]);
        self::assertResponseStatusCodeSame(422, 'a ticked photo needs the declaration');

        $this->client->request('GET', '/konto/locations/'.$venue->number().'/website-fotos');
        $this->client->submitForm('Weiter');
        self::assertResponseRedirects('/konto/locations/'.$venue->number().'/einrichten/kontakt', 303);
        self::assertSame(
            ['rejected', 'rejected'],
            $this->connection->fetchFirstColumn('SELECT status FROM photo_candidates WHERE id IN (?, ?)', [$saal->id(), $bach->id()]),
        );
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::new()->afterInstantiate(static function (Venue $venue): void {
            $venue->changeContact(null, null, 'https://www.'.self::DOMAIN.'/', null);
        })->create(['name' => 'Remise am Bach', 'status' => VenueStatus::Unpublished]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'remise-am-bach']);

        return $venue;
    }

    private function previewToken(Venue $venue): string
    {
        return $this->tokens()->forId(VenuePreviewFactory::createOne(['venue' => $venue, 'campaignCode' => 'brief'])->id());
    }

    /**
     * From the preview page's button to the code page's redirect.
     */
    private function claim(string $token, string $email): void
    {
        $crawler = $this->client->request('GET', '/vorschau/'.$token);
        $this->client->submit($crawler->selectButton('Gratis übernehmen')->form());
        self::assertResponseRedirects('/konto/uebernehmen', 303);
        $this->client->followRedirect();
        $this->client->submitForm('Code schicken', [
            'register[firstName]' => 'Rita',
            'register[lastName]' => 'Bach',
            'register[email]' => $email,
        ]);
    }

    private function mailedCode(string $email): string
    {
        self::assertResponseRedirects('/konto/uebernehmen/code', 303);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', $email);
        self::assertEmailHtmlBodyContains($mail, '30 Minuten');
        self::assertEmailHtmlBodyNotContains($mail, 'Rita', 'nothing the visitor typed');
        if (1 !== preg_match('/\b([0-9]{6})\b/', (string) $mail->getTextBody(), $match)) {
            self::fail('The mail carries no code.');
        }

        return $match[1];
    }

    private function tokens(): PreviewToken
    {
        $tokens = self::getContainer()->get(PreviewToken::class);
        self::assertInstanceOf(PreviewToken::class, $tokens);

        return $tokens;
    }
}
