<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Admin\ValueObject\AdminRole;
use App\Factory\AdminUserFactory;
use App\Geo\ValueObject\Canton;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Http\RecordingHttpCachePurger;
use App\Tests\Support\Portal\PortalSession;
use App\Tests\Support\Search\LandingFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * A new location from the registration form to a live public page (#45): every page is the rendered one, every step a
 * real form and none of it needs JavaScript. The photo goes through the `<noscript>` form. No factory stands in for a
 * step of the walk: the seeded towns and terms are all it starts from, and the five venues of the canton test.
 */
final class OnboardingWalkTest extends WebTestCase
{
    use AdminSession, PortalSession {
        AdminSession::setUp insteadof PortalSession;
        AdminSession::tearDown insteadof PortalSession;
        AdminSession::tearDown as private endSession;
    }
    use Factories;
    use ImportsTownDataset;
    use LandingFixtures;
    use SendsVenueMediaRequests;

    private const string EMAIL = 'petra@example.ch';
    private const string PASSWORD = 'ein-langes-passwort';
    private const string NAME = 'Seehaus Meilen';
    private const string TOWN = '8706 Meilen';
    private const string SUMMARY = 'Ein Haus direkt am See.';
    private const string QUEUE = '/admin/pruefung?tab=neu';
    private const string CANTON_PAGE = '/hochzeitslocations/zuerich';
    private const string LISTING = '/hochzeitslocations';
    private const string SITEMAP = '/sitemap-venues-1.xml';
    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endSession();
    }

    public function test_a_new_location_walks_from_registration_to_a_live_page(): void
    {
        $number = $this->walkToTheCheck();
        $this->assertPublicPage($number, Response::HTTP_GONE);

        $this->pressPublish($number, 'Veröffentlichen');
        $this->assertPublicPage($number, Response::HTTP_GONE);
        self::assertSame(self::EMAIL, $this->connection->fetchOne(
            'SELECT c.email FROM crm_contacts c JOIN crm_companies l ON l.id = c.company_id JOIN venues v ON v.id = l.venue_id WHERE v.number = ?',
            [$number],
        ));

        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $this->logInAs(AdminRole::Editor);
        $this->accept($number);

        $this->assertPublicPage($number, Response::HTTP_OK);
        self::assertSelectorTextContains('h1', self::NAME);
        self::assertSelectorTextContains('main', self::SUMMARY);
        self::assertSame(1, $this->invoiceTasks($number));
    }

    public function test_a_resubmitted_offline_location_goes_back_online(): void
    {
        $number = $this->walkToTheCheck();
        $this->pressPublish($number, 'Veröffentlichen');
        AdminUserFactory::createOne(['role' => AdminRole::Admin]);
        $this->logInAs(AdminRole::Editor);
        $this->accept($number);
        $this->assertPublicPage($number, Response::HTTP_OK);

        $crawler = $this->client->request('GET', '/konto/locations/'.$number);
        $this->client->submit($crawler->filter('form[action$="/offline"]')->form());
        self::assertResponseRedirects('/konto/locations/'.$number);
        self::assertSame('unpublished', $this->venueStatus($number));
        $this->assertPublicPage($number, Response::HTTP_GONE);

        $crawler = $this->client->request('GET', '/konto/locations/'.$number);
        $crawler = $this->client->click($crawler->selectLink('Wieder veröffentlichen')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('/konto/locations/'.$number.'/einrichten/pruefen', $this->client->getRequest()->getPathInfo());
        self::assertSame('Schritt 6 von 6', $crawler->filter('.progress__label')->text());

        $this->pressPublish($number, 'Wieder veröffentlichen');
        $this->assertPublicPage($number, Response::HTTP_GONE);

        $this->accept($number);

        $this->assertPublicPage($number, Response::HTTP_OK);
        self::assertSame(1, $this->invoiceTasks($number));
    }

    public function test_the_accepted_location_appears_in_listing_sitemap_and_canton_page(): void
    {
        $this->inCanton(Canton::ZH, 5);
        $number = $this->walkToTheCheck();
        $this->pressPublish($number, 'Veröffentlichen');
        $path = '/hochzeitslocation/'.$this->slug($number);

        foreach ([self::LISTING, self::CANTON_PAGE] as $page) {
            $links = $this->cardLinks($page);
            self::assertCount(5, $links, $page);
            self::assertNotContains($path, $links, $page);
        }
        $locs = $this->sitemapLocs();
        self::assertCount(5, $locs);
        self::assertNotContains('http://localhost'.$path, $locs);

        $this->logInAs(AdminRole::Editor);
        $purger = self::getContainer()->get(RecordingHttpCachePurger::class);
        self::assertInstanceOf(RecordingHttpCachePurger::class, $purger);
        $purger->venues = [];
        $purger->listings = 0;

        $this->accept($number);

        self::assertSame([$number], $purger->venues);
        self::assertSame(1, $purger->listings);
        foreach ([self::LISTING, self::CANTON_PAGE] as $page) {
            $links = $this->cardLinks($page);
            self::assertCount(6, $links, $page);
            self::assertContains($path, $links, $page);
        }
        $locs = $this->sitemapLocs();
        self::assertCount(6, $locs);
        self::assertContains('http://localhost'.$path, $locs);
    }

    /**
     * Registration, the mail's link, which logs the manager in, then steps 1 to 5 of the wizard, ending on the check.
     */
    private function walkToTheCheck(): int
    {
        // Admin's setUp leaves `cache.portal_register` alone, and every test here registers.
        $this->forgetPortalThrottling();
        $this->registerWithTheMailsLink();

        // Saving the password logged the manager in, and with no location `/konto` opens the wizard.
        $this->client->followRedirect();
        self::assertResponseRedirects('/konto/locations/neu');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('Schritt 1 von 6', $crawler->filter('.progress__label')->text());
        // The combobox island's fallback: a plain field, so the town can be typed without JavaScript.
        self::assertSame('text', $crawler->filter('input[name="new_location[town]"]')->attr('type'));

        $this->client->submit($crawler->filter('form[name="new_location"]')->form(), [
            'new_location[name]' => self::NAME,
            'new_location[capacityMax]' => '120',
            'new_location[town]' => self::TOWN,
        ]);
        $number = $this->connection->fetchOne('SELECT number FROM venues WHERE name = ?', [self::NAME]);
        self::assertIsInt($number);
        self::assertResponseRedirects($this->step($number, 'texte'), Response::HTTP_SEE_OTHER);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[name="venue_texts"]')->form(), ['venue_texts[texts][de][summary]' => self::SUMMARY]);
        self::assertResponseRedirects($this->step($number, 'ausstattung'), Response::HTTP_SEE_OTHER);

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('form[name="venue_terms"]')->form();
        $this->tick($form, 'venue_terms[building]', 'castle');
        $this->client->submit($form, ['venue_terms[mainBuilding]' => 'castle']);
        self::assertResponseRedirects($this->step($number, 'veranstaltung'), Response::HTTP_SEE_OTHER);

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('form[name="venue_event"]')->form(), ['venue_event[texts][de][ambience]' => 'Elegant am Wasser']);
        self::assertResponseRedirects($this->step($number, 'fotos'), Response::HTTP_SEE_OTHER);

        // A manager who leaves here comes back to the first step the location lacks.
        $this->client->request('GET', '/konto/locations/'.$number.'/einrichten');
        self::assertResponseRedirects($this->step($number, 'fotos'));

        $crawler = $this->client->followRedirect();
        $form = $crawler->filter('noscript form[name="wizard_photo"]')->form();
        // The crawler's own upload takes a path and no client name, so the file goes in as PHP would hand it over.
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['wizard_photo' => ['file' => $this->photoFile()]]);
        self::assertResponseRedirects($this->step($number, 'fotos'), Response::HTTP_SEE_OTHER);

        $crawler = $this->client->followRedirect();
        self::assertSame('1 Foto', $crawler->filter('noscript p.muted')->text());

        $crawler = $this->client->click($crawler->selectLink('Weiter')->link());
        self::assertResponseIsSuccessful();
        self::assertSame($this->step($number, 'kontakt'), $this->client->getRequest()->getPathInfo());

        $form = $crawler->filter('form[name="venue_contact"]')->form();
        self::assertSame(self::EMAIL, $form->getValues()['venue_contact[inquiryEmail]']);
        $this->client->submit($form);
        self::assertResponseRedirects($this->step($number, 'pruefen'), Response::HTTP_SEE_OTHER);
        self::assertSame(self::EMAIL, $this->connection->fetchOne('SELECT inquiry_email FROM venues WHERE number = ?', [$number]));

        return $number;
    }

    private function registerWithTheMailsLink(): void
    {
        $this->client->request('GET', '/konto/registrieren');
        $this->client->submitForm('Konto erstellen', [
            'register[firstName]' => 'Petra',
            'register[lastName]' => 'Keller',
            'register[email]' => self::EMAIL,
        ]);
        self::assertResponseRedirects('/konto/anmelden', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', self::EMAIL);
        $link = 1 === preg_match('~/konto/passwort-neu/[^"\s]+~', (string) $mail->getHtmlBody(), $match) ? $match[0] : '';
        self::assertNotSame('', $link);

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Passwort speichern', [
            'portal_password[plainPassword][first]' => self::PASSWORD,
            'portal_password[plainPassword][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/konto', Response::HTTP_SEE_OTHER);
    }

    /**
     * Step 6 with the rights ticked: "Wird geprüft", a mail to the manager and one to the site's address.
     */
    private function pressPublish(int $number, string $button): void
    {
        $crawler = $this->client->request('GET', $this->step($number, 'pruefen'));
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton($button)->form(), ['submit_location[rights]' => '1']);
        self::assertResponseRedirects($this->step($number, 'pruefen'), Response::HTTP_SEE_OTHER);

        self::assertEmailCount(2);
        $mails = array_map(static function (mixed $mail): array {
            self::assertInstanceOf(Email::class, $mail);

            return [$mail->getTo()[0]->getAddress(), $mail->getSubject()];
        }, self::getMailerMessages());
        self::assertSame([
            [self::EMAIL, 'Wir prüfen '.self::NAME],
            ['hallo@deine-eventlocation.ch', 'Neue Location zur Prüfung: '.self::NAME],
        ], $mails);

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wird geprüft', $crawler->filter('p[role="status"]')->text());
        self::assertCount(0, $crawler->filter('form[name="submit_location"]'));
        self::assertSame('submitted', $this->submissionStatus($number));
        self::assertSame('unpublished', $this->venueStatus($number));
    }

    /**
     * The tab "Neue Locations": the preview first, then "Annehmen".
     */
    private function accept(int $number): void
    {
        $crawler = $this->client->request('GET', self::QUEUE);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('#review-submissions tbody tr');
        self::assertCount(1, $row);
        self::assertStringContainsString(self::NAME, $row->text());

        $preview = $this->client->click($row->selectLink('Vorschau')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('/admin/locations/'.$number.'/vorschau', $this->client->getRequest()->getPathInfo());
        self::assertStringContainsString(self::NAME, $preview->filter('h1')->text());

        $crawler = $this->client->request('GET', self::QUEUE);
        $this->client->submit($crawler->selectButton('Annehmen')->form());
        self::assertResponseRedirects(self::QUEUE, Response::HTTP_SEE_OTHER);

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', self::EMAIL);
        self::assertEmailHeaderSame($mail, 'Subject', self::NAME.' ist online');

        self::assertSame('published', $this->venueStatus($number));
        self::assertSame('accepted', $this->submissionStatus($number));
    }

    private function assertPublicPage(int $number, int $status): void
    {
        $this->client->request('GET', '/hochzeitslocation/'.$this->slug($number));

        self::assertResponseStatusCodeSame($status);
    }

    /**
     * @return list<string>
     */
    private function cardLinks(string $page): array
    {
        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful($page);

        return $crawler->filter('a.card__link')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
    }

    /**
     * @return list<string>
     */
    private function sitemapLocs(): array
    {
        $this->client->request('GET', self::SITEMAP);
        self::assertResponseIsSuccessful();
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML((string) $this->client->getResponse()->getContent()));

        $locs = [];
        foreach ($xml->getElementsByTagNameNS(self::SITEMAP_NS, 'loc') as $loc) {
            $locs[] = $loc->textContent;
        }

        return $locs;
    }

    private function tick(Form $form, string $name, string $value): void
    {
        $boxes = $form->get($name);
        self::assertIsArray($boxes);
        foreach ($boxes as $box) {
            if ($box instanceof ChoiceFormField && [$value] === $box->availableOptionValues()) {
                $box->tick();

                return;
            }
        }

        self::fail(\sprintf('The form has no "%s" box for "%s".', $name, $value));
    }

    private function step(int $number, string $step): string
    {
        return '/konto/locations/'.$number.'/einrichten/'.$step;
    }

    private function slug(int $number): string
    {
        $slug = $this->connection->fetchOne("SELECT t.slug FROM venue_translations t JOIN venues v ON v.id = t.venue_id WHERE v.number = ? AND t.locale = 'de'", [$number]);
        self::assertIsString($slug);

        return $slug;
    }

    private function invoiceTasks(int $number): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM crm_tasks t JOIN crm_companies l ON l.id = t.company_id JOIN venues v ON v.id = l.venue_id WHERE t.title = 'Rechnung stellen' AND v.number = ?",
            [$number],
        );
    }

    private function venueStatus(int $number): mixed
    {
        return $this->connection->fetchOne('SELECT status FROM venues WHERE number = ?', [$number]);
    }

    private function submissionStatus(int $number): mixed
    {
        return $this->connection->fetchOne('SELECT s.status FROM portal_submissions s JOIN venues v ON v.id = s.venue_id WHERE v.number = ?', [$number]);
    }
}
