<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Admin\ValueObject\AdminRole;
use App\Cms\VenueMediaApi;
use App\Factory\RoomFactory;
use App\Factory\VenueFactory;
use App\Factory\VenuePhotoFactory;
use App\Tests\Support\Admin\AdminSession;
use App\Tests\Support\Media\TestImages;
use App\Venues\Entity\Venue;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The photo manager's JSON endpoints (#25, Step 7.4): upload, order, alt, room, remove; each answers the
 * full photo list (PF10) and the errors of `VenueMediaApi`.
 */
final class PhotoApiTest extends WebTestCase
{
    use AdminSession {
        tearDown as private endAdminSession;
    }
    use Factories;
    use SendsVenueMediaRequests;

    private const string TOO_MANY_PIXELS = 'Das Foto hat zu viele Pixel, höchstens 20 Megapixel. Bitte verkleinern und noch einmal hochladen.';
    private const string UNREADABLE = 'Das Foto lässt sich nicht lesen. Bitte als JPEG, PNG oder WebP neu speichern.';
    private const string TOO_TALL = 'Das Foto ist zu hoch: höchstens 2-mal so hoch wie breit. Bitte zuschneiden und noch einmal hochladen.';

    protected function tearDown(): void
    {
        $this->removeVenueMedia();
        $this->endAdminSession();
    }

    public function test_an_upload_adds_a_gallery_photo_and_answers_the_list(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();

        $this->upload(self::url($venue), $this->photoFile(), token: $this->csrfToken());

        self::assertResponseStatusCodeSame(201);
        $answer = $this->answer();
        self::assertFalse($answer['duplicate']);
        self::assertIsArray($answer['photos']);
        self::assertCount(1, $answer['photos']);
        self::assertIsArray($answer['photos'][0]);
        self::assertSame($answer['photo'], $answer['photos'][0]['id']);
        self::assertSame([null, 1], [$answer['photos'][0]['roomId'], $answer['photos'][0]['position']]);
        self::assertStringStartsWith('/media/'.$venue->number().'/', (string) $answer['photos'][0]['thumbnailUrl']);
        self::assertNotNull($this->connection->fetchOne('SELECT cms_edited_at FROM venues WHERE id = ?', [$venue->id()]));
    }

    public function test_the_same_file_twice_is_one_photo_answered_with_200(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();
        $bytes = TestImages::uniformJpeg(400, 300, 10, 120, 200);

        $this->upload(self::url($venue), $this->photoFile($bytes), token: $token);
        self::assertResponseStatusCodeSame(201);
        $first = $this->answer()['photo'];
        $this->upload(self::url($venue), $this->photoFile($bytes), token: $token);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([true, $first], [$this->answer()['duplicate'], $this->answer()['photo']]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_upload_into_a_room(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $room = RoomFactory::createOne(['venue' => $venue]);

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => $room->id()], $this->csrfToken());

        self::assertResponseStatusCodeSame(201);
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);
        self::assertSame($room->id(), $photos[0]['roomId']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function refusedFiles(): iterable
    {
        yield 'a GIF' => ['gif', 'bild.gif', 'image/gif', 'Bitte ein Foto als JPEG, PNG oder WebP hochladen.'];
        yield 'an empty file' => ['empty', 'leer.jpg', 'image/jpeg', 'Die Datei ist leer.'];
        yield 'a PNG far above its cap' => ['huge', 'riesig.png', 'image/png', self::TOO_MANY_PIXELS];
        yield 'an RGB PNG just above its cap' => ['png-rgb', 'plan.png', 'image/png', self::TOO_MANY_PIXELS];
        yield 'an RGBA PNG just above its cap' => ['png-rgba', 'plan.png', 'image/png', self::TOO_MANY_PIXELS];
        yield 'a palette PNG just above its cap' => ['png-palette', 'plan.png', 'image/png', self::TOO_MANY_PIXELS];
        yield 'a PNG at its cap reaches gd' => ['png-at-cap', 'plan.png', 'image/png', self::UNREADABLE];
        yield 'a JPEG above the cap' => ['jpeg-huge', 'riesig.jpg', 'image/jpeg', self::TOO_MANY_PIXELS];
        yield 'a 20 MP JPEG at 3:2 reaches gd' => ['jpeg-landscape', 'gross.jpg', 'image/jpeg', self::UNREADABLE];
        yield 'a 20 MP JPEG at the portrait limit reaches gd' => ['jpeg-at-ratio', 'hoch.jpg', 'image/jpeg', self::UNREADABLE];
        yield 'a 20 MP JPEG panorama reaches gd' => ['jpeg-panorama', 'breit.jpg', 'image/jpeg', self::UNREADABLE];
        yield 'a JPEG one row above the portrait limit' => ['jpeg-above-ratio', 'hoch.jpg', 'image/jpeg', self::TOO_TALL];
        yield 'a JPEG at 20 MP and 1:2.5' => ['jpeg-1-2.5', 'hoch.jpg', 'image/jpeg', self::TOO_TALL];
        yield 'a JPEG at 20 MP and 1:3' => ['jpeg-1-3', 'hoch.jpg', 'image/jpeg', self::TOO_TALL];
        yield 'a wide JPEG that EXIF turns above the limit' => ['jpeg-turned', 'gedreht.jpg', 'image/jpeg', self::TOO_TALL];
        yield 'a tall JPEG that EXIF turns wide' => ['jpeg-turned-tall', 'gedreht.jpg', 'image/jpeg', self::TOO_TALL];
        yield 'a WebP just above its cap' => ['webp-above-cap', 'gross.webp', 'image/webp', self::TOO_MANY_PIXELS];
        yield 'a WebP above the portrait limit' => ['webp-tall', 'hoch.webp', 'image/webp', self::TOO_TALL];
        yield 'a PNG above the portrait limit' => ['png-tall', 'hoch.png', 'image/png', self::TOO_TALL];
        yield 'a body gd cannot decode' => ['corrupt', 'kaputt.png', 'image/png', self::UNREADABLE];
        yield 'a JPEG that decodes but has 101 scans' => ['jpeg-scans', 'scans.jpg', 'image/jpeg', self::UNREADABLE];
        yield 'a PDF' => ['pdf', 'foto.jpg', 'image/jpeg', 'Bitte ein Foto als JPEG, PNG oder WebP hochladen.'];
        yield 'a file name that is not UTF-8' => ['gif', "bild\xff.gif", 'image/gif', 'Bitte ein Foto als JPEG, PNG oder WebP hochladen.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedFiles')]
    public function test_an_upload_refuses(string $kind, string $name, string $mime, string $message): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $bytes = match ($kind) {
            'gif' => self::gif(),
            'empty' => '',
            'huge' => TestImages::pngHeader(30000, 30000),
            'png-rgb' => TestImages::pngHeaderOnly(5001, 4000),
            'png-rgba' => TestImages::pngHeaderOnly(5001, 4000, 6),
            'png-palette' => TestImages::pngHeaderOnly(5001, 4000, 3),
            'png-at-cap' => TestImages::pngHeaderOnly(5000, 4000, 6),
            'jpeg-huge' => TestImages::jpegHeader(7000, 6000),
            'jpeg-landscape' => TestImages::jpegHeader(5477, 3651),
            'jpeg-at-ratio' => TestImages::jpegHeader(3162, 6324),
            'jpeg-panorama' => TestImages::jpegHeader(7745, 2581),
            'jpeg-above-ratio' => TestImages::jpegHeader(3000, 6001),
            'jpeg-1-2.5' => TestImages::jpegHeader(2828, 7071),
            'jpeg-1-3' => TestImages::jpegHeader(2581, 7745),
            'jpeg-turned' => TestImages::withOrientation(TestImages::jpegHeaderWithScan(6001, 3000), 6),
            'jpeg-turned-tall' => TestImages::withOrientation(TestImages::jpegHeaderWithScan(2581, 7745), 6),
            'webp-above-cap' => TestImages::webpHeader(4473, 4472),
            'webp-tall' => TestImages::webpHeader(3000, 6001),
            'png-tall' => TestImages::pngHeaderOnly(2000, 4001),
            'corrupt' => TestImages::pngHeader(400, 300),
            'jpeg-scans' => TestImages::jpegWithScans(400, 300, 101),
            'pdf' => "%PDF-1.4\n%fiktiv\n",
            default => self::fail($kind),
        };
        $file = 'empty' === $kind ? $this->photoFile("\0", $name, $mime) : $this->photoFile($bytes, $name, $mime);
        if ('empty' === $kind) {
            file_put_contents($file->getPathname(), '');
        }

        $this->upload(self::url($venue), $file, token: $this->csrfToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['file' => [$message]], $this->errors());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_a_progressive_jpeg_and_one_at_the_scan_limit_are_stored(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();
        $progressive = TestImages::progressiveJpeg();
        self::assertGreaterThan(1, substr_count($progressive, "\xFF\xDA"));

        $this->upload(self::url($venue), $this->photoFile($progressive), token: $token);
        self::assertResponseStatusCodeSame(201);

        $this->upload(self::url($venue), $this->photoFile(TestImages::jpegWithScans(400, 300, 100)), token: $token);
        self::assertResponseStatusCodeSame(201);
    }

    public function test_no_file_is_missing_and_a_dropped_body_is_too_large(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();

        $this->upload(self::url($venue), null, ['roomId' => ''], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['file' => ['Bitte ein Foto wählen.']], $this->errors());

        // PHP above post_max_size: no file, no field, only the length (Probe 5).
        $this->client->request('POST', self::url($venue), server: [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            'CONTENT_LENGTH' => '27500304',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['file' => ['Das Foto ist zu gross, höchstens 10 MB.']], $this->errors());
    }

    public function test_a_room_id_that_is_no_uuid_is_a_422_and_another_venues_room_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => 'saal'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('roomId', $this->errors());

        $this->upload(self::url($venue), $this->photoFile(), ['roomId' => RoomFactory::createOne()->id()], $token);
        self::assertResponseStatusCodeSame(404);
        self::assertArrayHasKey('', $this->errors());
    }

    public function test_an_unknown_venue_is_a_json_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $token = $this->csrfToken();

        $this->upload('/admin/api/cms/venues/999999999/photos', $this->photoFile(), token: $token);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $this->json('PUT', '/admin/api/cms/venues/999999999/photos/order', ['roomId' => null, 'ids' => []], $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_reorder_saves_the_order_and_a_foreign_list_is_a_409(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $first = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $second = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 2]);
        $token = $this->csrfToken();

        $this->json('PUT', self::url($venue).'/order', ['roomId' => null, 'ids' => [$second->id(), $first->id()]], $token);

        self::assertResponseIsSuccessful();
        self::assertSame([$second->id(), $first->id()], self::ids($this->answer()));

        $this->json('PUT', self::url($venue).'/order', ['roomId' => null, 'ids' => [$first->id()]], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([''], array_keys($this->errors()));

        $this->json('PUT', self::url($venue).'/order', ['roomId' => null, 'ids' => 'alle'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('ids', $this->errors());
    }

    public function test_an_alt_save_uses_the_photo_token(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $token = $this->csrfToken();
        $this->upload(self::url($venue), $this->photoFile(), token: $token);
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);
        [$id, $loadedAt] = [(string) $photos[0]['id'], (string) $photos[0]['token']];

        $this->json('PUT', self::url($venue).'/'.$id.'/alt', ['loadedAt' => $loadedAt, 'alts' => ['de' => 'Der Saal am Abend']], $token);
        self::assertResponseIsSuccessful();
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);
        self::assertSame(['de' => 'Der Saal am Abend'], $photos[0]['alts']);

        $this->json('PUT', self::url($venue).'/'.$id.'/alt', ['loadedAt' => '1', 'alts' => ['de' => 'Später']], $token);
        self::assertResponseStatusCodeSame(409);

        $this->json('PUT', self::url($venue).'/'.$id.'/alt', ['loadedAt' => $loadedAt, 'alts' => ['de' => str_repeat('a', 701)]], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('alts.de', $this->errors());

        $this->json('PUT', self::url($venue).'/'.$id.'/alt', '{nope', $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_a_body_above_the_json_limit_is_refused_before_it_is_decoded(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $this->json('PUT', self::url($venue).'/order', ['roomId' => null, 'ids' => [$photo->id()], 'padding' => str_repeat('x', VenueMediaApi::MAX_BODY_BYTES)], $this->csrfToken());

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('ids', $this->errors());
    }

    public function test_a_photo_moves_to_a_room_and_is_removed(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $room = RoomFactory::createOne(['venue' => $venue]);
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);
        $token = $this->csrfToken();

        $this->json('PUT', self::url($venue).'/'.$photo->id().'/room', ['roomId' => $room->id()], $token);
        self::assertResponseIsSuccessful();
        $photos = $this->answer()['photos'];
        self::assertIsArray($photos);
        self::assertIsArray($photos[0]);
        self::assertSame($room->id(), $photos[0]['roomId']);

        $this->json('PUT', self::url($venue).'/'.$photo->id().'/room', ['roomId' => 7], $token);
        self::assertResponseStatusCodeSame(422);

        foreach (['{nope', '[]', ''] as $malformed) {
            $this->json('PUT', self::url($venue).'/'.$photo->id().'/room', $malformed, $token);
            self::assertResponseStatusCodeSame(422, 'A body without roomId must not read as "to the gallery".');
        }
        self::assertSame($room->id(), $this->connection->fetchOne('SELECT room_id FROM venue_photos WHERE id = ?', [$photo->id()]));

        $this->json('DELETE', self::url($venue).'/'.$photo->id(), token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->answer()['photos']);
    }

    public function test_another_venues_photo_is_a_404(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $foreign = VenuePhotoFactory::createOne(['position' => 1]);
        $token = $this->csrfToken();

        $this->json('DELETE', self::url($venue).'/'.$foreign->id(), token: $token);
        self::assertResponseStatusCodeSame(404);
        $this->json('PUT', self::url($venue).'/'.$foreign->id().'/alt', ['loadedAt' => '1', 'alts' => ['de' => 'x']], $token);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE id = ?', [$foreign->id()]));
    }

    public function test_without_the_csrf_header_a_write_is_refused(): void
    {
        $this->logInAs(AdminRole::Editor);
        $venue = self::venue();
        $photo = VenuePhotoFactory::createOne(['venue' => $venue, 'position' => 1]);

        $this->upload(self::url($venue), $this->photoFile());
        self::assertResponseStatusCodeSame(403);
        $this->json('DELETE', self::url($venue).'/'.$photo->id());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]));
    }

    public function test_an_anonymous_visitor_gets_a_json_401(): void
    {
        $venue = self::venue();

        $this->upload(self::url($venue), $this->photoFile(), token: 'any');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    private static function venue(): Venue
    {
        return VenueFactory::new()->withoutLocation()->create();
    }

    private static function url(Venue $venue): string
    {
        return '/admin/api/cms/venues/'.$venue->number().'/photos';
    }

    private static function gif(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $answer
     *
     * @return list<string>
     */
    private static function ids(array $answer): array
    {
        $photos = $answer['photos'];
        self::assertIsArray($photos);

        return array_values(array_map(static fn (mixed $photo): string => \is_array($photo) ? (string) $photo['id'] : '', $photos));
    }
}
