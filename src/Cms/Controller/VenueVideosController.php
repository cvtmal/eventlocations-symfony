<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ValueObject\VenueNumber;
use App\Cms\ValueObject\VideoPagesSetup;
use App\Cms\VenueVideoPages;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Videos tab (G15): `VenueVideoPages` inside the venue record.
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/videos', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenueVideosController extends AbstractController
{
    public function __construct(
        private readonly VenueVideoPages $pages,
    ) {
    }

    #[Route('', name: 'admin_locations_videos', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        return $this->pages->index(self::setup(), $number, $request);
    }

    #[Route('/{id}/nach-oben', name: 'admin_locations_videos_up', methods: ['POST'])]
    public function up(int $number, string $id, Request $request): Response
    {
        return $this->pages->move(self::setup(), $number, $id, -1, $request);
    }

    #[Route('/{id}/nach-unten', name: 'admin_locations_videos_down', methods: ['POST'])]
    public function down(int $number, string $id, Request $request): Response
    {
        return $this->pages->move(self::setup(), $number, $id, 1, $request);
    }

    #[Route('/{id}/loeschen', name: 'admin_locations_videos_remove', methods: ['POST'])]
    public function remove(int $number, string $id, Request $request): Response
    {
        return $this->pages->remove(self::setup(), $number, $id, $request);
    }

    private static function setup(): VideoPagesSetup
    {
        return new VideoPagesSetup('admin_locations_videos', 'cms_video', 'admin/cms/venue/videos.html.twig', static fn (VenueHeader $venue): array => []);
    }
}
