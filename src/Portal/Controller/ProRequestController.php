<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Event\PortalEvents;
use App\Portal\Event\ProRequested;
use App\Portal\ReadModel\LocationPlanQuery;
use App\Portal\ReadModel\PortalProfileQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\Security\PortalVenueVoter;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Pro anfragen" on the plan card (F12): a manager asks for the Pro plan of a location. Nothing is written here and
 * nothing is paid: the request is published as `ProRequested`, the CRM turns it into a task for staff, and staff
 * switch the tier and invoice by hand. A location already on Pro, or a site with no Pro tier, has nothing to ask for
 * and answers 404, so a second press from a stale page is not a second request of a plan the location holds. A press
 * on a Free location twice publishes twice; the CRM's listener keeps one open task.
 *
 * The token is the stateless `submit` of the portal's other plain forms. A bad one changes nothing, flashes a hint and
 * goes back to the page, because `#[IsCsrfTokenValid]` would send a logged-in manager to the login.
 */
#[IsGranted(PortalVenueVoter::EDIT, subject: 'number')]
final class ProRequestController extends AbstractController
{
    private const string TOKEN = 'submit';

    /** The tabs that show the plan card (F11) and so may be the way back; anything else goes to Angaben. */
    private const array BACK_ROUTES = ['portal_location_photos', 'portal_location_rooms', 'portal_location_documents', 'portal_location_videos', 'portal_location_last_minute'];

    public function __construct(
        private readonly VenueHeaderQuery $headers,
        private readonly LocationPlanQuery $plans,
        private readonly PortalProfileQuery $profiles,
        private readonly PortalEvents $events,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/konto/locations/{number}/pro-anfragen', name: 'portal_location_pro_request', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(int $number, Request $request, #[CurrentUser] PortalIdentity $identity): Response
    {
        $venue = $this->headers->find($number) ?? throw $this->createNotFoundException();
        if (!$this->plans->forVenue($venue->id, $this->clock->now())->canRequestPro) {
            throw $this->createNotFoundException();
        }

        // all(): get() would answer an array `_token` with a 400.
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', PortalFlash::message('plan.flash.failed'));

            return $this->back($number, $request);
        }

        $profile = $this->profiles->find($identity->id()) ?? throw $this->createNotFoundException();
        $this->events->proRequested(new ProRequested($venue->id, $venue->name, $profile->email, $profile->firstName, $profile->lastName));
        $this->addFlash('success', PortalFlash::message('plan.flash.requested'));

        return $this->back($number, $request);
    }

    private function back(int $number, Request $request): Response
    {
        $back = $request->request->all()['back'] ?? null;
        $route = \is_string($back) && \in_array($back, self::BACK_ROUTES, true) ? $back : 'portal_location';

        return $this->redirectToRoute($route, ['number' => $number], Response::HTTP_SEE_OTHER);
    }
}
