<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Security\AdminIdentity;
use App\Crm\Command\ChangeDealLostReason;
use App\Crm\Command\MoveDealStage;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\LostReasonRequired;
use App\Crm\Exception\StaleProperty;
use App\Crm\Form\DealStageData;
use App\Crm\Form\DealStageType;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Messenger\AuditableCommand;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The record's stage form (N8), PRG: the path of a move without JS or drag, and where the board's fallback leads, and
 * the one place a lost deal's reason changes: same stage, another reason, on a form that showed the deal as lost. Any
 * other same stage sends no command (P1). A refusal is a flash.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealStageFormController extends AbstractController
{
    #[Route('/admin/crm/deals/{id}/phase', name: 'admin_crm_deals_stage', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, DealStageQuery $stages, FormFactoryInterface $forms, MessageBusInterface $commandBus, #[CurrentUser] AdminIdentity $user): Response
    {
        $state = $stages->stateOf($id) ?? throw $this->createNotFoundException();

        $data = new DealStageData();
        $form = $forms->createNamed(DealStageType::NAME, DealStageType::class, $data, ['stages' => $stages->codes()]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } elseif ($state->stage !== $data->stage) {
            $move = new MoveDealStage($id, (string) $data->stage, (string) $data->expected, $user->id(), $data->lostReason);
            $this->addFlash(...$this->send($move, 'deal.record.stage_flash.moved', $commandBus));
        } elseif ($state->isLost && $data->expected === $state->stage && $data->lostReason?->value !== $state->lostReason) {
            $this->addFlash(...$this->send(new ChangeDealLostReason($id, $data->lostReason), 'deal.record.stage_flash.reason', $commandBus));
        } else {
            $this->addFlash('success', CrmFlash::message('deal.record.stage_flash.moved'));
        }

        return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{string, mixed} a flash's type and message
     */
    private function send(AuditableCommand $command, string $done, MessageBusInterface $commandBus): array
    {
        try {
            $commandBus->dispatch($command);

            return ['success', CrmFlash::message($done)];
        } catch (HandlerFailedException $failure) {
            return ['error', CrmFlash::message(match (true) {
                [] !== $failure->getWrappedExceptions(StaleProperty::class) => 'deal.record.stage_flash.stale',
                [] !== $failure->getWrappedExceptions(LostReasonRequired::class) => 'deal.record.stage_flash.lost_reason',
                [] !== $failure->getWrappedExceptions(DealNotFound::class) => throw $this->createNotFoundException(),
                default => throw $failure,
            })];
        }
    }
}
