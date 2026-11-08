<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Admin\Security\AdminIdentity;
use App\Crm\Command\DeleteDeal;
use App\Crm\Exception\DealNotFound;
use App\Crm\Form\DealStageData;
use App\Crm\Form\DealStageType;
use App\Crm\ReadModel\DealPaymentOfferQuery;
use App\Crm\ReadModel\DealRecordQuery;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\TaskListQuery;
use App\Crm\TimelineProps;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\RecordKind;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A deal record (D27): the page, and the forms its cards post. They are PRG with a flash for a refusal, as a company's.
 * The Kontakte card lists the Location's contact rows, read-only (#55, Q4).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/deals/{id}', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class DealRecordController extends AbstractController
{
    public const string DELETE_FORM = 'delete_deal';

    public function __construct(
        private readonly DealRecordQuery $deals,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_crm_deals_show', methods: ['GET'])]
    public function show(string $id, OwnerOptionsQuery $owners, DealStageQuery $stages, DealPaymentOfferQuery $offers, TimelineProps $timeline, TaskListQuery $tasks, #[CurrentUser] AdminIdentity $user): Response
    {
        $deal = $this->deals->find($id) ?? throw $this->createNotFoundException();
        $staff = $owners->all();

        $stageData = new DealStageData();
        $stageData->stage = $deal->stage;
        $stageData->expected = $deal->stage;
        $stageData->lostReason = $deal->lostReason;

        return $this->render('admin/crm/deal/show.html.twig', [
            'deal' => $deal,
            'owners' => OwnerOptionsQuery::selectable($staff, $deal->ownerId),
            'timeline' => $timeline->of(RecordKind::Deal, $id, $user->id(), OwnerOptionsQuery::selectable($staff)),
            'tasks' => $tasks->openOf(RecordKind::Deal, $id),
            'stage_form' => $this->forms->createNamed(DealStageType::NAME, DealStageType::class, $stageData, [
                'action' => $this->generateUrl('admin_crm_deals_stage', ['id' => $id]),
                'stages' => $stages->codes(),
            ])->createView(),
            'delete_form' => $this->isGranted('ROLE_ADMIN') ? $this->deleteForm($id)->createView() : null,
            'payment' => $offers->forDeal($id),
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    #[Route('/loeschen', name: 'admin_crm_deals_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $form = $this->deleteForm($id);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        $deal = $this->deals->find($id) ?? throw $this->createNotFoundException();
        try {
            $this->commandBus->dispatch(new DeleteDeal($id));
        } catch (HandlerFailedException $failure) {
            throw [] !== $failure->getWrappedExceptions(DealNotFound::class) ? $this->createNotFoundException() : $failure;
        }
        $this->addFlash('success', CrmFlash::message('deal.record.delete.flash', ['name' => $deal->name]));

        return $this->redirectToRoute('admin_crm_deals', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function deleteForm(string $id): FormInterface
    {
        return $this->forms->createNamed(self::DELETE_FORM, ConfirmActionType::class, null, [
            'action' => $this->generateUrl('admin_crm_deals_delete', ['id' => $id]),
        ]);
    }
}
