<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Security\AdminIdentity;
use App\Crm\Command\MoveDealStage;
use App\Crm\CrmApi;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ReadModel\DealStageState;
use App\Crm\ReadModel\DealTierOfferQuery;
use App\Crm\TierOfferProps;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One move on the board (D17-a): `{"stage", "expected", "lostReason"}` in, `{"stage", "closedOn", "tierOffer"}` out, the
 * offer being the won prompt's form (D20). The deal already at the target answers without a dispatch, whatever `expected`
 * says, so two people choosing the same stage leave one audit row (D17-b, P1); the handler repeats the check under its
 * locks.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['PATCH'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class DealStageController extends AbstractController
{
    #[Route('/admin/api/crm/deals/{id}/stage', name: 'admin_api_crm_deal_stage', requirements: ['id' => Requirement::UUID], methods: ['PATCH'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        string $id,
        Request $request,
        #[CurrentUser] AdminIdentity $user,
        CrmApi $api,
        DealStageQuery $stages,
        DealTierOfferQuery $offers,
        TierOfferProps $offerProps,
        MessageBusInterface $commandBus,
    ): JsonResponse {
        $move = $api->stageMove($request, $stages->codes());
        if ($move instanceof JsonResponse) {
            return $move;
        }

        $state = $stages->stateOf($id);
        if (null === $state) {
            return $api->notFound();
        }
        if ($move->stage === $state->stage) {
            return self::answer($state, $offerProps->of($offers->forDeal($id)));
        }

        try {
            $commandBus->dispatch(new MoveDealStage($id, $move->stage, $move->expected, $user->id(), $move->lostReason));
        } catch (HandlerFailedException $failure) {
            return $api->failure($failure);
        }

        $moved = $stages->stateOf($id);

        return null === $moved ? $api->notFound() : self::answer($moved, $offerProps->of($offers->forDeal($id)));
    }

    /**
     * @param ?array<string, string> $tierOffer
     */
    private static function answer(DealStageState $state, ?array $tierOffer): JsonResponse
    {
        return new JsonResponse(['stage' => $state->stage, 'closedOn' => $state->closedOn, 'tierOffer' => $tierOffer]);
    }
}
