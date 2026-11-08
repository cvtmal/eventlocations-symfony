<?php

declare(strict_types=1);

namespace App\Tests\Application\Portal;

use App\Factory\PortalVenueAccessFactory;
use App\Factory\RoomFactory;
use App\Factory\SubmissionFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Portal\Entity\PortalUser;
use App\Tests\Application\Cms\SendsVenueMediaRequests;
use App\Tests\Support\Portal\PortalSession;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\VenueStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;

/**
 * The photo limit of a plan (F12) reaches a manager in words (`TierLimits` → `VenueRuleViolation::tooManyPhotos()`):
 * the plan's name and the limit, in the JSON API's `file` error and in the wizard's no-JavaScript form alike. The
 * count is the location's whole set, gallery and room photos together; a refused upload stores nothing.
 */
final class PhotoLimitMessageTest extends WebTestCase
{
    use Factories;
    use PortalSession {
        tearDown as private endPortalSession;
    }
    use SendsVenueMediaRequests;

    private const string FREE_MESSAGE = 'Mit dem Plan «Gratis» sind höchstens 6 Fotos möglich. Entferne zuerst ein Foto; mehr gibt es mit einem grösseren Plan.';
    private const string PRO_MESSAGE = 'Mit dem Plan «Pro» sind höchstens 60 Fotos möglich. Entferne zuerst ein Foto; mehr gibt es mit einem grösseren Plan.';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endPortalSession();
    }

    public function test_the_api_names_the_plan_and_the_limit_of_a_free_location(): void
    {
        $venue = $this->location($this->logInAsManager());
        $this->fillGallery($venue, 6);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => [self::FREE_MESSAGE]], $this->errors());
        self::assertSame(6, $this->photoCount($venue));
    }

    public function test_the_count_is_the_whole_set_gallery_and_rooms_together(): void
    {
        $venue = $this->location($this->logInAsManager());
        $room = RoomFactory::createOne(['venue' => $venue, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 1]);
        VenuePhotoFactory::createOne(['venue' => $venue, 'room' => $room, 'position' => 2]);
        $this->fillGallery($venue, 4);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => [self::FREE_MESSAGE]], $this->errors());
        self::assertSame(6, $this->photoCount($venue));
    }

    public function test_the_last_photo_a_free_location_may_hold_is_taken(): void
    {
        $venue = $this->location($this->logInAsManager());
        $this->fillGallery($venue, 5);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(6, $this->photoCount($venue));
    }

    public function test_a_pro_location_names_its_own_limit(): void
    {
        $venue = $this->location($this->logInAsManager(), KnownTiers::PRO);
        $this->fillGallery($venue, 60);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => [self::PRO_MESSAGE]], $this->errors());
    }

    public function test_the_limit_is_the_tier_rows_so_a_staff_edit_moves_it(): void
    {
        $venue = $this->location($this->logInAsManager());
        $this->fillGallery($venue, 6);
        $this->connection->executeStatement("UPDATE tiers SET photos_max = 7, name = 'Starter' WHERE code = 'free'");

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->upload('/konto/api/locations/'.$venue->number().'/photos', $this->photoFile(), token: $this->csrfToken());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['file' => ['Mit dem Plan «Starter» sind höchstens 7 Fotos möglich. Entferne zuerst ein Foto; mehr gibt es mit einem grösseren Plan.']], $this->errors());
    }

    public function test_the_wizard_form_shows_the_same_message_beside_the_file(): void
    {
        $venue = $this->location($this->logInAsManager(), draft: true);
        $this->fillGallery($venue, 6);

        $crawler = $this->uploadThroughForm($venue, $this->photoFile());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(self::FREE_MESSAGE, $crawler->filter('#wizard_photo_file_error')->text());
        self::assertSame(6, $this->photoCount($venue));
    }

    /**
     * The island reads the portal token from a page of `/konto`, as the browser does.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/konto/profil');

        return (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');
    }

    private function location(PortalUser $manager, string $tier = KnownTiers::FREE, bool $draft = false): Venue
    {
        $venue = VenueFactory::new()->withoutLocation()->create(['tier' => $tier, 'status' => $draft ? VenueStatus::Unpublished : VenueStatus::Published]);
        if ($draft) {
            SubmissionFactory::createOne(['venue' => $venue]);
        }
        PortalVenueAccessFactory::new()->active()->create(['user' => $manager, 'venue' => $venue]);

        return $venue;
    }

    private function fillGallery(Venue $venue, int $photos): void
    {
        for ($position = 1; $position <= $photos; ++$position) {
            VenuePhotoFactory::createOne(['venue' => $venue, 'position' => $position]);
        }
    }

    private function uploadThroughForm(Venue $venue, UploadedFile $file): Crawler
    {
        $form = $this->client->request('GET', '/konto/locations/'.$venue->number().'/einrichten/fotos')->filter('form[name="wizard_photo"]')->form();

        // The crawler's own upload takes a path and no client name, so the file goes in as PHP would hand it over.
        return $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['wizard_photo' => ['file' => $file]]);
    }

    private function photoCount(Venue $venue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]);
    }
}
