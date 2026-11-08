<?php

declare(strict_types=1);

namespace App\Cms;

use App\Cms\Form\SafeClientName;
use App\Cms\ReadModel\VenueIdQuery;
use App\Cms\ReadModel\VenuePhotosQuery;
use App\Cms\ValueObject\PhotoUploadLimits;
use App\Media\ImageVariantGenerator;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\ValueObject\AddedPhoto;
use App\Venues\ValueObject\PhotoGrant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * One photo upload (#25, CMS-6, D1, D2, D5, D7): multipart `file` and optional `roomId` (empty = the
 * gallery). The limits sit here and only here, in front of gd (D3, PF5): `Assert\Image` and `getimagesize()`
 * read the size from the header, so an oversized photo never reaches `ImageVariantGenerator`. One pixel cap and
 * one file size for every type; the portrait limit is `PhotoUploadLimits::isTooTall()`.
 * The variants are written before the answer (S12: synchronous, one file per request).
 *
 * 201 `{"photo": id, "duplicate": false, "photos": [...]}` for a new photo, 200 with `duplicate: true` for
 * the same file in the same scope (D5); 422 `{"errors": {"file"|"roomId": [...]}}`; 404 for an unknown
 * venue or another venue's room.
 *
 * The caller says who vouches for the file (G1), and has to say it even when nobody does: staff pass their
 * attestation, a manager whose declaration is on record passes the upload grant, and null stores a photo that is
 * not public until a `GrantPhotoRights` covers it.
 */
final class VenuePhotoUpload
{
    /** The size in `cms.photo.too_large` too (SI, 10,000,000 bytes, as the island's client check). */
    public const string MAX_SIZE = '10M';

    /** `MAX_SIZE` in bytes, for the photo manager's client check before a file is sent. */
    public const int MAX_BYTES = 10_000_000;

    /**
     * libjpeg walks every scan, so a small file with thousands of them pins a worker (#44, F-M1). A progressive
     * JPEG has about ten. `FF DA` cannot occur in entropy data, only as a marker or inside metadata.
     */
    private const int MAX_JPEG_SCANS = 100;

    private readonly PhotoUploadLimits $limits;

    public function __construct(
        private readonly VenueIdQuery $venues,
        private readonly VenuePhotosQuery $photos,
        private readonly VenueMediaApi $api,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $commandBus,
        private readonly EnabledLocale $locales,
        #[Autowire('%app.media_max_pixels%')]
        private readonly int $maxPixels,
        #[Autowire('%app.media_max_portrait_ratio%')]
        float $maxPortraitRatio,
    ) {
        $this->limits = new PhotoUploadLimits($maxPortraitRatio);
    }

    public function add(int $number, Request $request, ?PhotoGrant $grant): JsonResponse
    {
        if (null === $this->venues->byNumber($number)) {
            return $this->api->notFound();
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            // Above post_max_size PHP drops the whole body: no file, no field, only the length (Probe 5).
            $dropped = 0 === $request->files->count() && 0 === $request->request->count() && (int) $request->server->get('CONTENT_LENGTH') > 0;

            return $this->api->invalid('file', $dropped ? 'cms.photo.too_large' : 'cms.photo.missing');
        }

        $roomId = VenueMediaApi::roomId($request->request->all()['roomId'] ?? null);
        if (false === $roomId) {
            return $this->api->invalid('roomId', 'cms.photo.room.invalid');
        }

        $file = SafeClientName::of($file);
        $violations = $this->validator->validate(['file' => $file], new Assert\Collection(['file' => $this->rule()]));
        foreach ($violations as $violation) {
            if (Assert\Image::TOO_MANY_PIXEL_ERROR === $violation->getCode()) {
                return $this->api->invalid('file', 'cms.photo.too_many_pixels', ['megapixels' => intdiv($this->maxPixels, 1_000_000)]);
            }
        }
        if (\count($violations) > 0) {
            return $this->api->violations($violations);
        }

        $mime = (string) $file->getMimeType();
        $extension = AddVenuePhoto::EXTENSIONS[$mime] ?? null;
        if (null === $extension) {
            return $this->api->invalid('file', 'cms.photo.type');
        }

        if ($this->isTooTall($file->getPathname())) {
            return $this->api->invalid('file', 'cms.photo.too_tall', ['ratio' => $this->maxPortraitRatio()]);
        }

        if ($this->hasTooManyScans($file->getPathname(), $mime)) {
            return $this->api->invalid('file', 'cms.photo.unreadable');
        }

        try {
            $added = $this->commandBus->dispatch(new AddVenuePhoto($number, $roomId, $file->getPathname(), $extension, $grant))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            return $this->api->failure($failure);
        }
        \assert($added instanceof AddedPhoto);

        $view = $this->photos->forVenue($number, $this->locales->default());
        if (null === $view) {
            return $this->api->notFound();
        }

        return new JsonResponse(
            ['photo' => $added->photoId, 'duplicate' => $added->duplicate, 'photos' => $view->photosArray()],
            $added->duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }

    /**
     * The portrait limit for a caller with a form of its own: `rule()` cannot hold it.
     */
    public function isTooTall(string $path): bool
    {
        $size = @getimagesize($path);

        return false !== $size && $this->limits->isTooTall($size[0], $size[1], ImageVariantGenerator::swapsAxes($path));
    }

    /**
     * The scan limit for a caller with a form of its own: `rule()` cannot hold it either.
     */
    public function hasTooManyScans(string $path, string $mime): bool
    {
        return 'image/jpeg' === $mime && substr_count((string) file_get_contents($path), "\xFF\xDA") > self::MAX_JPEG_SCANS;
    }

    public function maxPortraitRatio(): float
    {
        return $this->limits->maxPortraitRatio;
    }

    /**
     * Built in code, because the pixel cap is a parameter (`MEDIA_MAX_PIXELS`, D2). The portrait limit is not here: `minRatio` rounds the ratio to two decimals,
     * and it cannot see EXIF. Every message is a `validators` key without the file's name or size (`{{ name }}` is still passed, hence SafeClientName).
     */
    public function rule(): Assert\Image
    {
        return new Assert\Image(
            maxSize: self::MAX_SIZE,
            mimeTypes: array_keys(AddVenuePhoto::EXTENSIONS),
            maxPixels: $this->maxPixels,
            notFoundMessage: 'cms.photo.failed',
            notReadableMessage: 'cms.photo.failed',
            maxSizeMessage: 'cms.photo.too_large',
            mimeTypesMessage: 'cms.photo.type',
            disallowEmptyMessage: 'cms.photo.empty',
            uploadIniSizeErrorMessage: 'cms.photo.too_large',
            uploadFormSizeErrorMessage: 'cms.photo.too_large',
            uploadPartialErrorMessage: 'cms.photo.failed',
            uploadNoFileErrorMessage: 'cms.photo.missing',
            uploadNoTmpDirErrorMessage: 'cms.photo.failed',
            uploadCantWriteErrorMessage: 'cms.photo.failed',
            uploadExtensionErrorMessage: 'cms.photo.failed',
            uploadErrorMessage: 'cms.photo.failed',
            sizeNotDetectedMessage: 'cms.photo.unreadable',
        );
    }
}
