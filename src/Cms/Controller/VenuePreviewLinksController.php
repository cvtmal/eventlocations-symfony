<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\PreviewLinkData;
use App\Cms\Form\PreviewLinkType;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\CmsFlash;
use App\Cms\ValueObject\VenueNumber;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\RevokeVenuePreview;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\PreviewToken;
use App\Venues\ReadModel\VenuePreviewLink;
use App\Venues\ReadModel\VenuePreviewQuery;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The entry's "Vorschau-Links" tab (G5): staff issue a private preview link per campaign, copy it or
 * its QR code (an SVG to print on a letter) and revoke it. The links are built from the row id
 * (`Venues\PreviewToken`), so a link can be shown again at any time. Expired and revoked links stay
 * listed without their address. CSRF of the revoke form is checked here (see
 * `VenuePhotoRightsController`: `#[IsCsrfTokenValid]` would send an editor to the login page).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/locations/{number}/vorschau-links', requirements: ['number' => VenueNumber::REQUIREMENT, 'id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class VenuePreviewLinksController extends AbstractController
{
    public const string CSRF_ID = 'cms_preview_links';

    private const int QR_SIZE = 480;

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly VenuePreviewQuery $previews,
        private readonly PreviewToken $tokens,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'admin_locations_preview_links', methods: ['GET', 'POST'])]
    public function index(int $number, Request $request): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        $data = new PreviewLinkData();
        $form = $this->createForm(PreviewLinkType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $id = $this->commandBus->dispatch($data->toCommand($number))->last(HandledStamp::class)?->getResult();
            } catch (HandlerFailedException $failure) {
                $this->rethrowNotFound($failure);

                throw $failure;
            }
            \assert(\is_string($id));
            $this->addFlash('success', CmsFlash::message('preview_links.flash.issued'));

            return $this->redirectToRoute('admin_locations_preview_links', ['number' => $number, '_fragment' => 'link-'.$id], Response::HTTP_SEE_OTHER);
        }

        $now = $this->clock->now();

        return $this->render('admin/cms/venue/preview_links.html.twig', [
            'venue' => $venue,
            'form' => $form,
            'links' => array_map(fn (VenuePreviewLink $link): array => [
                'link' => $link,
                'state' => $link->state($now),
                'url' => $link->isOpen($now) ? $this->url($link->id) : null,
            ], $this->previews->forVenue($number)),
            'csrf_id' => self::CSRF_ID,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{id}/widerrufen', name: 'admin_locations_preview_links_revoke', methods: ['POST'])]
    public function revoke(int $number, string $id, Request $request): Response
    {
        if (null === $this->headers->find($number)) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'flash.action_failed');

            return $this->back($number);
        }

        try {
            $this->commandBus->dispatch(new RevokeVenuePreview($number, $id));
        } catch (HandlerFailedException $failure) {
            $this->rethrowNotFound($failure);

            throw $failure;
        }
        $this->addFlash('success', CmsFlash::message('preview_links.flash.revoked'));

        return $this->back($number);
    }

    /**
     * The link as a QR code to print (an SVG file); only while the link answers.
     */
    #[Route('/{id}/qr.svg', name: 'admin_locations_preview_links_qr', methods: ['GET'])]
    public function qr(int $number, string $id): Response
    {
        $link = $this->previews->find($id);
        if (null === $link || $link->venueNumber !== $number || !$link->isOpen($this->clock->now())) {
            throw $this->createNotFoundException();
        }

        $svg = (new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE, 2), new SvgImageBackEnd())))->writeString($this->url($id));

        return new Response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => \sprintf('inline; filename="vorschau-%d-%s.svg"', $number, $link->campaignCode),
        ]);
    }

    private function url(string $id): string
    {
        return $this->generateUrl('venue_preview', ['token' => $this->tokens->forId($id)], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function rethrowNotFound(HandlerFailedException $failure): void
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            throw $this->createNotFoundException();
        }
    }

    private function back(int $number): RedirectResponse
    {
        return $this->redirectToRoute('admin_locations_preview_links', ['number' => $number, '_fragment' => 'vorschau-links'], Response::HTTP_SEE_OTHER);
    }
}
