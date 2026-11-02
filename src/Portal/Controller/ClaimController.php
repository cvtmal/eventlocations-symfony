<?php

declare(strict_types=1);

namespace App\Portal\Controller;

use App\Cms\ReadModel\VenueListFilter;
use App\Cms\ReadModel\VenueListQuery;
use App\Cms\ReadModel\VenueListRow;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Command\RequestVenueAccess;
use App\Portal\Exception\VenueNotClaimable;
use App\Portal\ReadModel\ManagerAccessQuery;
use App\Portal\Security\PortalIdentity;
use App\Portal\ValueObject\PortalFlash;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * POR-5: a manager finds a published location and asks for access. The number travels in the POST body, so the
 * route takes no `{number}` and `PortalRoutesAreClosedTest` walks it without a strict parameter. Asking twice
 * reads first and dispatches nothing, because a no-op command would still write an audit row.
 */
final class ClaimController extends AbstractController
{
    private const int MIN_LENGTH = 2;
    private const int MAX_LENGTH = 100;
    private const int RESULTS = 20;
    private const string TOKEN = 'submit';

    public function __construct(
        private readonly ManagerAccessQuery $access,
    ) {
    }

    #[Route('/konto/locations/suchen', name: 'portal_claims_search', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function search(Request $request, #[CurrentUser] PortalIdentity $identity, VenueListQuery $venues): Response
    {
        $q = self::query($request);
        $rows = null === $q ? [] : $venues->quickSearch($q, self::RESULTS, VenueListFilter::STATUS_PUBLISHED);

        return $this->render('portal/claims/search.html.twig', [
            'q' => $q,
            'rows' => $rows,
            'statuses' => $this->access->statuses($identity->id(), array_map(static fn (VenueListRow $row): int => $row->number, $rows)),
        ]);
    }

    #[Route('/konto/locations/zugriff', name: 'portal_claims_ask', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function ask(Request $request, #[CurrentUser] PortalIdentity $identity, MessageBusInterface $commandBus): Response
    {
        $input = $request->request->all();
        $number = $input['number'] ?? null;
        $token = $input['_token'] ?? null;
        $q = $input['q'] ?? null;
        $back = $this->generateUrl('portal_claims_search', \is_string($q) ? ['q' => $q] : []);

        if (!\is_string($number) || 1 !== preg_match('/^'.VenueNumber::REQUIREMENT.'$/D', $number)
            || !\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', PortalFlash::message('claims.flash.failed'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        $number = (int) $number;
        if ([] !== $this->access->statuses($identity->id(), [$number])) {
            $this->addFlash('success', PortalFlash::message('claims.flash.already'));

            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        try {
            $commandBus->dispatch(new RequestVenueAccess($identity->id(), $number));
            $this->addFlash('success', PortalFlash::message('claims.flash.requested'));
        } catch (HandlerFailedException $failure) {
            if ([] === $failure->getWrappedExceptions(VenueNotClaimable::class)) {
                throw $failure;
            }
            $this->addFlash('error', PortalFlash::message('claims.flash.not_available'));
        }

        return $this->redirect($back, Response::HTTP_SEE_OTHER);
    }

    /**
     * The usable query, or null. It reads `all()` because `get()` would answer an array `q` with a 400.
     */
    private static function query(Request $request): ?string
    {
        $q = $request->query->all()['q'] ?? null;
        if (!\is_string($q) || !mb_check_encoding($q, 'UTF-8') || str_contains($q, "\0")) {
            return null;
        }

        $q = mb_substr(trim($q), 0, self::MAX_LENGTH);

        return mb_strlen($q) < self::MIN_LENGTH ? null : $q;
    }
}
