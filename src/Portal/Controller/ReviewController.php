<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueHeader;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Command\GrantVenueAccess;
use App\Portal\Command\RevokeVenueAccess;
use App\Portal\Event\LocationManagerConfirmed;
use App\Portal\Event\PortalEvents;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\ManagerAccessQuery;
use App\Portal\ReadModel\PendingSubmissionQuery;
use App\Portal\ReadModel\PortalAccessRequestBadge;
use App\Portal\ReadModel\PortalAccessRequestQuery;
use App\Portal\ReadModel\PortalProfileQuery;
use App\Portal\ReadModel\PortalProfileRow;
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
 * Staff decide on access requests (POR-5). A decision reads the open request first, so a double click sends no
 * second command and leaves no second audit row. The mail and the event come after the dispatch, because the
 * handler's transaction has committed only then: neither can undo the decision, a failed mail only warns staff.
 */
#[IsGranted('ROLE_EDITOR')]
final class ReviewController extends AbstractController
{
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly PortalMailer $mailer,
        private readonly ManagerAccessQuery $access,
        private readonly PortalProfileQuery $profiles,
        private readonly VenueHeaderQuery $venues,
    ) {
    }

    #[Route('/admin/pruefung', name: 'admin_portal_review', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function index(Request $request, PortalAccessRequestQuery $requests, PendingSubmissionQuery $submissions, PortalAccessRequestBadge $badge): Response
    {
        // No choice in the URL: the first tab with items, so the sidebar's badge never lands on an empty tab.
        $tab = match ($request->query->get('tab')) {
            'neu' => 'neu',
            'requests' => 'requests',
            default => 0 === $badge->count() && $badge->submissions() > 0 ? 'neu' : 'requests',
        };

        return $this->render('admin/portal/review/index.html.twig', [
            'tab' => $tab,
            'rows' => 'requests' === $tab ? $requests->oldest() : [],
            'submissions' => 'neu' === $tab ? $submissions->oldest() : [],
            'request_count' => $badge->count(),
            'submission_count' => $badge->submissions(),
        ]);
    }

    #[Route('/admin/pruefung/anfragen/{userId}/{number}/annehmen', name: 'admin_portal_review_approve', requirements: ['userId' => Requirement::UUID, 'number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function approve(string $userId, int $number, Request $request, PortalEvents $events): Response
    {
        if (!$this->hasValidToken($request)) {
            return $this->back('error', 'review.flash.action_failed');
        }

        $pending = $this->pending($userId, $number);
        if (null === $pending) {
            return $this->back('error', 'review.flash.gone');
        }
        [$profile, $venue] = $pending;

        $this->commandBus->dispatch(new GrantVenueAccess($userId, $venue->id, GrantVenueAccess::VIA_REVIEW));
        $mailed = $this->mailer->sendAccessApproved($profile->email, $profile->firstName, $venue->name);
        $events->managerConfirmed(new LocationManagerConfirmed($venue->id, $venue->name, $profile->email, $profile->firstName, $profile->lastName, $profile->phone));

        return $mailed ? $this->back('success', 'review.flash.approved') : $this->back('error', 'review.flash.approved_mail_failed');
    }

    #[Route('/admin/pruefung/anfragen/{userId}/{number}/ablehnen', name: 'admin_portal_review_reject', requirements: ['userId' => Requirement::UUID, 'number' => VenueNumber::REQUIREMENT], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function reject(string $userId, int $number, Request $request): Response
    {
        if (!$this->hasValidToken($request)) {
            return $this->back('error', 'review.flash.action_failed');
        }

        $pending = $this->pending($userId, $number);
        if (null === $pending) {
            return $this->back('error', 'review.flash.gone');
        }
        [$profile, $venue] = $pending;

        $this->commandBus->dispatch(new RevokeVenueAccess($userId, $venue->id, GrantVenueAccess::VIA_REVIEW));
        $mailed = $this->mailer->sendAccessRejected($profile->email, $profile->firstName, $venue->name);

        return $mailed ? $this->back('success', 'review.flash.rejected') : $this->back('error', 'review.flash.rejected_mail_failed');
    }

    /**
     * The account and the location of a request that is still open, or null.
     *
     * @return array{PortalProfileRow, VenueHeader}|null
     */
    private function pending(string $userId, int $number): ?array
    {
        if (PortalVenueAccessStatus::Pending !== ($this->access->statuses($userId, [$number])[$number] ?? null)) {
            return null;
        }

        $profile = $this->profiles->find($userId);
        $venue = $this->venues->find($number);

        return null === $profile || null === $venue ? null : [$profile, $venue];
    }

    private function hasValidToken(Request $request): bool
    {
        $token = $request->request->all()['_token'] ?? null;

        return \is_string($token) && $this->isCsrfTokenValid(self::TOKEN, $token);
    }

    private function back(string $type, string $key): Response
    {
        $this->addFlash($type, PortalFlash::message($key));

        return $this->redirectToRoute('admin_portal_review', status: Response::HTTP_SEE_OTHER);
    }
}
