<?php

declare(strict_types=1);

namespace App\Cms\Controller\Api;

use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenuePhotoUpload;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One photo upload for staff (#25, CMS-6): `VenuePhotoUpload` holds the limits and the answers.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class PhotoUploadController extends AbstractController
{
    public const string MAX_SIZE = VenuePhotoUpload::MAX_SIZE;

    public const int MAX_BYTES = VenuePhotoUpload::MAX_BYTES;

    public function __construct(
        private readonly VenuePhotoUpload $upload,
    ) {
    }

    #[Route('/admin/api/cms/venues/{number}/photos', name: 'admin_api_cms_photos_add', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request): JsonResponse
    {
        return $this->upload->add($number, $request);
    }
}
