<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueIdQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Event\LocationManagerConfirmed;
use App\Portal\Event\PortalEvents;
use App\Portal\Form\PortalEmailData;
use App\Portal\Form\PortalEmailType;
use App\Portal\ReadModel\ManagerAccessQuery;
use App\Portal\ReadModel\PortalProfileQuery;
use App\Portal\ReadModel\VenueManagersQuery;
use App\Portal\ValueObject\PortalFlash;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff add and remove a location's managers on the panel of the venue page. Neither mails anybody. As in the
 * review queue, each reads the row first, because a no-op command would still write an audit row. Adding
 * publishes the event after the dispatch, once the handler's transaction has committed.
 */
#[IsGranted('ROLE_EDITOR')]
final class VenueManagersController extends AbstractController
{
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly ManagerAccessQuery $access,
    ) {
    }

    #[Route('/admin/pruefung/locations/{number}/manager', name: 'admin_portal_review_manager_add', requirements: ['number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function add(int $number, Request $request, VenueHeaderQuery $venues, VenueManagersQuery $managers, PortalProfileQuery $profiles, PortalEvents $events): Response
    {
        $venue = $venues->find($number) ?? throw $this->createNotFoundException();

        $form = $this->createForm(PortalEmailType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->back($number, 'error', 'review.flash.manager_invalid');
        }

        $data = $form->getData();
        \assert($data instanceof PortalEmailData);

        $userId = $managers->accountIdByEmail((string) $data->email);
        $profile = null === $userId ? null : $profiles->find($userId);
        if (null === $userId || null === $profile) {
            return $this->back($number, 'error', 'review.flash.manager_unknown');
        }
        if ($this->isManager($userId, $number)) {
            return $this->back($number, 'success', 'review.flash.manager_known');
        }

        $this->commandBus->dispatch(new GrantVenueAccess($userId, $venue->id, GrantVenueAccess::VIA_PANEL));
        $events->managerConfirmed(new LocationManagerConfirmed($venue->id, $venue->name, $profile->email, $profile->firstName, $profile->lastName, $profile->phone));

        return $this->back($number, 'success', 'review.flash.manager_added');
    }

    #[Route('/admin/pruefung/locations/{number}/manager/{userId}/entfernen', name: 'admin_portal_review_manager_remove', requirements: ['number' => VenueNumber::REQUIREMENT, 'userId' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function remove(int $number, string $userId, Request $request, VenueIdQuery $venues): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            return $this->back($number, 'error', 'review.flash.action_failed');
        }

        $venueId = $venues->byNumber($number) ?? throw $this->createNotFoundException();
        if (!$this->isManager($userId, $number)) {
            return $this->back($number, 'error', 'review.flash.manager_gone');
        }

        $this->commandBus->dispatch(new RevokeVenueAccess($userId, $venueId, GrantVenueAccess::VIA_PANEL));

        return $this->back($number, 'success', 'review.flash.manager_removed');
    }

    private function isManager(string $userId, int $number): bool
    {
        return PortalVenueAccessStatus::Active === ($this->access->statuses($userId, [$number])[$number] ?? null);
    }

    private function back(int $number, string $type, string $key): Response
    {
        $this->addFlash($type, PortalFlash::message($key));

        return $this->redirectToRoute('admin_locations_edit', ['number' => $number, '_fragment' => 'portal-managers'], Response::HTTP_SEE_OTHER);
    }
}
