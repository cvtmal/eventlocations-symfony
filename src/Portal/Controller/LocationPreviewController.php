<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ValueObject\VenueNumber;
use App\Portal\Security\PortalVenueVoter;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\VenueDetailQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The manager's preview of their location's public page, published or not: staff's preview template, whose banner
 * leads back to `/konto`. Under `/konto` the portal's `no-store` and `noindex` headers apply.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationPreviewController extends AbstractController
{
    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/konto/locations/{number}/vorschau', name: 'portal_location_preview', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number): Response
    {
        $detail = $this->venues->detail($number, $this->locales->default(), includeUnpublished: true) ?? throw $this->createNotFoundException();

        return $this->render('admin/cms/venue/preview.html.twig', [
            'venue' => $detail,
            'back_url' => $this->generateUrl('portal_location', ['number' => $number]),
        ]);
    }
}
