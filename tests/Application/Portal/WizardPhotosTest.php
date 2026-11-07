<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\MediaRightsGrantFactory;
use App\Factory\PortalVenueAccessFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Media\TestImages;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The wizard's photo step (#45): the island of #44, and a plain form for a browser without JavaScript, which keeps the
 * API's limits. The photo is optional here: the gap stops the publish, not the next step.
 */
final class WizardPhotosTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    private const string RATE_LIMITED = 'Du hast in der letzten Stunde sehr viele Fotos hochgeladen. Bitte versuch es später noch einmal.';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_the_page_mounts_the_island_with_four_endpoints_and_no_room(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertResponseIsSuccessful();
        self::assertSame('Schritt 4 von 6', $crawler->filter('.progress__label')->text());
        $props = $this->props($crawler);
        self::assertIsArray($props['endpoints']);
        self::assertSame(['upload', 'order', 'alt', 'remove'], array_keys($props['endpoints']));
        self::assertSame([], $props['rooms']);
        self::assertSame('/konto/api/locations/'.$venue->number().'/photos', $props['endpoints']['upload']);
    }

    public function test_the_reload_url_is_the_wizard_step(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->client->request('GET', $this->url($venue));

        self::assertSame($this->url($venue), $this->props($crawler)['reloadUrl']);
    }

    public function test_the_noscript_form_uploads_the_first_photo_as_gallery_photo(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        $rows = $this->connection->fetchAllAssociative('SELECT room_id, position FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['room_id']);

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSame('1 Foto', $crawler->filter('noscript p.muted')->text());
    }

    public function test_the_noscript_upload_waits_for_the_declaration(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->photoCount($venue));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]), 'stored, not public: the declaration of the submit step grants it');
    }

    public function test_the_noscript_upload_is_granted_when_a_declaration_is_on_record(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->location($manager);
        $declared = VenuePhotoFactory::new()->withoutGrant()->create(['venue' => $venue, 'position' => 1]);
        MediaRightsGrantFactory::createOne(['photo' => $declared, 'grantor' => Grantor::manager($manager->id()), 'evidence' => GrantEvidence::Declaration]);

        $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseRedirects($this->url($venue), Response::HTTP_SEE_OTHER);
        $grant = $this->connection->fetchAssociative(
            'SELECT g.grantor_type, g.grantor_ref, g.evidence FROM media_rights_grants g JOIN venue_photos p ON p.id = g.venue_photo_id WHERE g.venue_id = ? AND p.id <> ?',
            [$venue->id(), $declared->id()],
        );
        self::assertSame(['manager', $manager->id(), 'upload'], $grant ? array_values($grant) : null);
    }

    public function test_a_bad_file_is_a_field_error_with_422(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->uploadThroughForm($venue, $this->photoFile('%PDF-1.7 kein Foto', 'foto.jpg', 'image/jpeg'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('Bitte ein Foto als JPEG, PNG oder WebP hochladen.', $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(0, $this->photoCount($venue));
    }

    public function test_a_crafted_file_name_stays_a_422(): void
    {
        $venue = $this->location($this->logInAsManager());

        $this->uploadThroughForm($venue, $this->photoFile('%PDF-1.7 kein Foto', "foto\xFF.jpg", 'image/jpeg'));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_a_photo_above_the_portrait_limit_is_refused_as_in_the_api(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->uploadThroughForm($venue, $this->photoFile(TestImages::uniformJpeg(100, 201, 10, 20, 30)));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Das Foto ist zu hoch', $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(0, $this->photoCount($venue));
    }

    public function test_a_jpeg_with_too_many_scans_is_refused_as_in_the_api(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->uploadThroughForm($venue, $this->photoFile(TestImages::jpegWithScans(400, 300, 101)));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('Das Foto lässt sich nicht lesen', $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(0, $this->photoCount($venue));
    }

    public function test_the_thirty_first_photo_is_refused(): void
    {
        $venue = $this->location($this->logInAsManager());
        for ($position = 1; $position <= AddVenuePhoto::MAX_PHOTOS; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }

        $crawler = $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNotSame('', $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(AddVenuePhoto::MAX_PHOTOS, $this->photoCount($venue));
    }

    public function test_the_upload_limiter_refuses_with_a_form_error(): void
    {
        $manager = $this->logInAsManager();
        $venue = $this->location($manager);
        $this->limiter()->create($manager->id())->consume(60);

        $crawler = $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(self::RATE_LIMITED, $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(0, $this->photoCount($venue));
    }

    public function test_weiter_needs_no_photo(): void
    {
        $venue = $this->location($this->logInAsManager());

        $crawler = $this->client->request('GET', $this->url($venue));
        $this->client->click($crawler->selectLink('Weiter')->link());

        self::assertResponseIsSuccessful();
        self::assertSame('/konto/locations/'.$venue->number().'/einrichten/kontakt', $this->client->getRequest()->getPathInfo());
    }

    private function location(PortalUser $manager): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['status' => VenueStatus::Unpublished]);
        SubmissionFactory::createOne(['venue' => $venue]);
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    private function uploadThroughForm(Venue $venue, UploadedFile $file): Crawler
    {
        $form = $this->client->request('GET', $this->url($venue))->filter('form[name="wizard_photo"]')->form();

        // The crawler's own upload takes a path and no client name, so the file goes in as PHP would hand it over.
        return $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['wizard_photo' => ['file' => $file]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(Crawler $crawler): array
    {
        $props = json_decode((string) $crawler->filter('[data-island="photo-manager"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);

        /* @var array<string, mixed> $props */
        return $props;
    }

    private function photoCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
    }

    private function limiter(): RateLimiterFactoryInterface
    {
        $limiter = self::getContainer()->get('limiter.portal_upload');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);

        return $limiter;
    }

    private function url(Venue $venue): string
    {
        return '/konto/locations/'.$venue->number().'/einrichten/fotos';
    }
}
