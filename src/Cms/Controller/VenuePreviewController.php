<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\VenueDetailQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The preview of a venue's public page (CMS-9, D27): the public detail template with a banner, published or
 * not, in the default locale. It sits under `/admin`, so the firewall guards it and the back office's
 * `no-store` and `noindex` headers apply; the template drops canonical, hreflang, JSON-LD and the inquiry
 * form. A venue without a default-locale translation has no page to show and is a 404.
 */
#[IsGranted('ROLE_EDITOR')]
final class VenuePreviewController extends AbstractController
{
    public function __construct(
        private readonly VenueDetailQuery $venues,
        private readonly EnabledLocale $locales,
    ) {
    }

    #[Route('/admin/locations/{number}/vorschau', name: 'admin_locations_preview', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number): Response
    {
        $detail = $this->venues->detail($number, $this->locales->default(), includeUnpublished: true) ?? throw $this->createNotFoundException();

        return $this->render('admin/cms/venue/preview.html.twig', ['venue' => $detail]);
    }
}
