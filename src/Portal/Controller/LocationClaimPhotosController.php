<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\ClaimPhotoImport;
use App\Portal\Form\ClaimPhotosData;
use App\Portal\Form\ClaimPhotosType;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Portal\ValueObject\WizardStep;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\Command\DecidePhotoCandidates;
use App\Venues\ReadModel\PhotoCandidateQuery;
use App\Venues\ReadModel\PhotoCandidateRow;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * G6: right after a claim, the photos the website import found (G3) for the entry. The claimant ticks the ones to
 * publish and gives the declaration (G1); `ClaimPhotoImport` fetches and adds them, each granted on that
 * declaration, and the rest are rejected. The candidates are links to the venue's own site, not pictures: the
 * back-office CSP allows no foreign image, and nothing is fetched before the claimant asks. Without candidates the
 * page sends straight on: to the wizard's contact step (the inquiry address is confirmed there), or to the editor
 * of an entry that is online already.
 *
 * The plan caps the ticks (F12): at most the photos its `photos_max` leaves free, so a claimant on Free cannot tick
 * more than `AddVenuePhoto` would take. An entry whose plan is full skips the step like one without candidates.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class LocationClaimPhotosController extends AbstractController
{
    public function __construct(
        private readonly PhotoCandidateQuery $candidates,
        private readonly VenueHeaderQuery $venues,
        private readonly ClaimPhotoImport $import,
        private readonly MessageBusInterface $commandBus,
        private readonly LocationPlanQuery $plans,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/konto/locations/{number}/website-fotos', name: 'portal_location_claim_photos', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $venue = $this->venues->find($number) ?? throw $this->createNotFoundException();
        $candidates = $this->candidates->open($number);
        $plan = $this->plans->forVenue($venue->id, $this->clock->now());
        $max = min(ClaimPhotoImport::MAX_PICKS, $plan->photosMax - $plan->photosUsed);
        if ([] === $candidates || $max <= 0) {
            return $this->next($number, $venue->isPublished, $request->isMethod('GET') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(ClaimPhotosType::class, new ClaimPhotosData(), ['candidates' => $candidates, 'max' => $max])->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('portal/location/claim_photos.html.twig', [
                'venue' => $venue,
                'form' => $form,
                'candidates' => $candidates,
                'max' => $max,
            ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        }

        $data = $form->getData();
        \assert($data instanceof ClaimPhotosData);
        $picked = array_values(array_filter($candidates, static fn (PhotoCandidateRow $row): bool => \in_array($row->id, $data->photos, true)));
        $left = array_values(array_filter($candidates, static fn (PhotoCandidateRow $row): bool => !\in_array($row->id, $data->photos, true)));

        if ([] === $picked) {
            $this->commandBus->dispatch(new DecidePhotoCandidates($number, [], array_map(static fn (PhotoCandidateRow $row): string => $row->id, $left)));
            $this->addFlash('success', PortalFlash::message('claim.flash.no_photos'));

            return $this->next($number, $venue->isPublished);
        }

        $failed = $this->import->import($number, $picked, $left, new PhotoGrant(Grantor::manager($identity->id()), GrantEvidence::Declaration, $data->derivatives));
        $added = \count($picked) - $failed;
        $this->addFlash(0 === $failed ? 'success' : 'error', PortalFlash::message(0 === $failed ? 'claim.flash.photos_added' : 'claim.flash.photos_failed', ['added' => $added, 'failed' => $failed]));

        return $this->next($number, $venue->isPublished);
    }

    private function next(int $number, bool $isPublished, int $status = Response::HTTP_SEE_OTHER): RedirectResponse
    {
        return $isPublished
            ? $this->redirectToRoute('portal_location', ['number' => $number], $status)
            : $this->redirectToRoute('portal_location_setup_step', ['number' => $number, 'step' => WizardStep::Contact->value], $status);
    }
}
