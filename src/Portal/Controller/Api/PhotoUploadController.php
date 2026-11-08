<?php

declare(strict_types=1);

namespace App\Portal\Controller\Api;

use App\Cms\ValueObject\VenueNumber;
use App\Cms\VenueMediaApi;
use App\Cms\VenuePhotoUpload;
use App\Portal\ManagerUploadGrant;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One photo upload for a manager (POR-8): staff's `VenuePhotoUpload`, to the gallery or, with `roomId`, to one of the
 * location's rooms (F11; a room of another venue is the handler's refusal). The limiter sits here,
 * so staff stay unlimited, and answers a 422 on `file`, because the island turns only a 422 into a text beside the
 * file; a 429 would show none and invite a retry. The photo is public at once only with a manager declaration on
 * record (`ManagerUploadGrant`); before that it waits for the declaration (G1).
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
#[IsCsrfTokenValid('portal_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class PhotoUploadController extends AbstractController
{
    public function __construct(
        private readonly VenuePhotoUpload $upload,
        private readonly VenueMediaApi $api,
        private readonly RateLimiterFactoryInterface $portalUploadLimiter,
        private readonly ManagerUploadGrant $grants,
    ) {
    }

    #[Route('/konto/api/locations/{number}/photos', name: 'portal_api_photos_add', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request, #[CurrentUser] PortalIdentity $identity): JsonResponse
    {
        if (!$this->portalUploadLimiter->create($identity->id())->consume()->isAccepted()) {
            return $this->api->invalid('file', 'portal.photo.rate_limited');
        }

        return $this->upload->add($number, $request, $this->grants->forUpload($identity->id(), $number));
    }
}
