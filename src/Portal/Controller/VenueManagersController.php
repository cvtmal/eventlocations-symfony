<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueIdQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\InviteLocationManager;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Event\LocationManagerConfirmed;
use App\Portal\Event\PortalEvents;
use App\Portal\Exception\PortalEmailTaken;
use App\Portal\Form\PortalManagerData;
use App\Portal\Form\PortalManagerType;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\ManagerAccessQuery;
use App\Portal\ReadModel\VenueManagersQuery;
use App\Portal\ValueObject\PortalFlash;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff add and remove a location's managers on the panel of the venue page. An address with no account gets one
 * and an invitation; an account with no password gets the invitation, one with a password a short notice; a
 * deactivated one is refused. Adding someone who is a manager already changes nothing, except that an open
 * invitation goes out again: that is the panel's resend button. As in the review queue, each action reads first,
 * because a no-op command would still write an audit row. Order: command, which commits, then mail, then event.
 * Removing mails nobody.
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
    public function add(int $number, Request $request, VenueHeaderQuery $venues, VenueManagersQuery $managers, PortalMailer $mailer, PortalEvents $events): Response
    {
        $venue = $venues->find($number) ?? throw $this->createNotFoundException();

        $form = $this->createForm(PortalManagerType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->back($number, 'error', 'review.flash.manager_invalid');
        }

        $data = $form->getData();
        \assert($data instanceof PortalManagerData && null !== $data->email);

        $account = $managers->accountByEmail($data->email);
        if (null === $account) {
            return $this->invite($number, $venue, mb_strtolower($data->email), trim((string) $data->firstName), trim((string) $data->lastName), $mailer, $events);
        }
        if (!$account->active) {
            return $this->back($number, 'error', 'invite.flash.deactivated');
        }
        if ($this->isManager($account->id, $number)) {
            if (!$account->invited) {
                return $this->back($number, 'success', 'review.flash.manager_known');
            }
            $mailed = $mailer->sendInvitation($account->id, $account->email, $account->firstName, $venue->name);

            return $mailed ? $this->back($number, 'success', 'invite.flash.resent') : $this->back($number, 'error', 'invite.flash.mail_failed');
        }

        $this->commandBus->dispatch(new GrantVenueAccess($account->id, $venue->id, GrantVenueAccess::VIA_PANEL));
        $mailed = $account->invited
            ? $mailer->sendInvitation($account->id, $account->email, $account->firstName, $venue->name)
            : $mailer->sendManagerAdded($account->email, $account->firstName, $venue->name);
        $events->managerConfirmed(new LocationManagerConfirmed($venue->id, $venue->name, $account->email, $account->firstName, $account->lastName, $account->phone));

        return $mailed ? $this->back($number, 'success', 'invite.flash.added_mailed') : $this->back($number, 'error', 'invite.flash.mail_failed');
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

    private function invite(int $number, VenueHeader $venue, string $email, string $firstName, string $lastName, PortalMailer $mailer, PortalEvents $events): Response
    {
        if ('' === $firstName || '' === $lastName) {
            return $this->back($number, 'error', 'invite.flash.names_needed');
        }

        $userId = EntityId::generate();
        try {
            $this->commandBus->dispatch(new InviteLocationManager($userId, $email, $firstName, $lastName, $venue->id));
        } catch (HandlerFailedException $failure) {
            if (!$failure->getPrevious() instanceof PortalEmailTaken) {
                throw $failure;
            }

            return $this->back($number, 'error', 'invite.flash.taken');
        }

        $mailed = $mailer->sendInvitation($userId, $email, $firstName, $venue->name);
        $events->managerConfirmed(new LocationManagerConfirmed($venue->id, $venue->name, $email, $firstName, $lastName, null));

        return $mailed ? $this->back($number, 'success', 'invite.flash.invited', ['email' => $email]) : $this->back($number, 'error', 'invite.flash.mail_failed');
    }

    private function isManager(string $userId, int $number): bool
    {
        return PortalVenueAccessStatus::Active === ($this->access->statuses($userId, [$number])[$number] ?? null);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function back(int $number, string $type, string $key, array $parameters = []): Response
    {
        $this->addFlash($type, PortalFlash::message($key, $parameters));

        return $this->redirectToRoute('admin_locations_edit', ['number' => $number, '_fragment' => 'portal-managers'], Response::HTTP_SEE_OTHER);
    }
}
