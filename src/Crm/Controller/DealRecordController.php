<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Admin\Form\ConfirmActionType;
use App\Admin\Security\AdminIdentity;
use App\Crm\Command\DeleteDeal;
use App\Crm\Command\LinkDealContact;
use App\Crm\Command\UnlinkDealContact;
use App\Crm\Exception\ContactOfAnotherCompany;
use App\Crm\Exception\DealNotFound;
use App\Crm\Form\DealContactData;
use App\Crm\Form\DealContactType;
use App\Crm\Form\DealStageData;
use App\Crm\Form\DealStageType;
use App\Crm\ReadModel\DealRecord;
use App\Crm\ReadModel\DealRecordQuery;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ReadModel\DealTierOfferQuery;
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
 * A link only offers contacts not on the deal and an unlink of one that is gone sends no command, so neither leaves
 * an audit row without a change (P1).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/deals/{id}', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class DealRecordController extends AbstractController
{
    public const string DELETE_FORM = 'delete_deal';
    /** The token id of the per-contact unlink buttons, plain forms because a page holds several. */
    public const string UNLINK_TOKEN = 'submit';

    public function __construct(
        private readonly DealRecordQuery $deals,
        private readonly FormFactoryInterface $forms,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_crm_deals_show', methods: ['GET'])]
    public function show(string $id, OwnerOptionsQuery $owners, DealStageQuery $stages, DealTierOfferQuery $offers, TimelineProps $timeline, TaskListQuery $tasks, #[CurrentUser] AdminIdentity $user): Response
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
            'contact_form' => $this->contactForm($deal)->createView(),
            'delete_form' => $this->isGranted('ROLE_ADMIN') ? $this->deleteForm($id)->createView() : null,
            'unlink_token' => self::UNLINK_TOKEN,
            'tier_offer' => $offers->forDeal($id),
        ]);
    }

    #[Route('/kontakte', name: 'admin_crm_deals_link_contact', methods: ['POST'])]
    public function link(string $id, Request $request): Response
    {
        $deal = $this->deals->find($id) ?? throw $this->createNotFoundException();
        $data = new DealContactData();
        $form = $this->contactForm($deal, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', CrmFlash::message('deal.record.contacts.flash.invalid'));
        } else {
            try {
                $this->commandBus->dispatch(new LinkDealContact($id, (string) $data->contact));
                $this->addFlash('success', CrmFlash::message('deal.record.contacts.flash.linked'));
            } catch (HandlerFailedException $failure) {
                if ([] === $failure->getWrappedExceptions(ContactOfAnotherCompany::class)) {
                    throw $failure;
                }
                $this->addFlash('error', CrmFlash::message('deal.record.contacts.flash.another_company'));
            }
        }

        return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id, '_fragment' => 'kontakte'], status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/kontakte/{contactId}/entfernen', name: 'admin_crm_deals_unlink_contact', requirements: ['contactId' => Requirement::UUID], methods: ['POST'])]
    public function unlink(string $id, string $contactId, Request $request): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::UNLINK_TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } else {
            $deal = $this->deals->find($id) ?? throw $this->createNotFoundException();
            if ($deal->hasContact($contactId)) {
                $this->commandBus->dispatch(new UnlinkDealContact($id, $contactId));
            }
            $this->addFlash('success', CrmFlash::message('deal.record.contacts.flash.unlinked'));
        }

        return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id, '_fragment' => 'kontakte'], status: Response::HTTP_SEE_OTHER);
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
    private function contactForm(DealRecord $deal, ?DealContactData $data = null): FormInterface
    {
        return $this->forms->createNamed(DealContactType::NAME, DealContactType::class, $data ?? new DealContactData(), [
            'action' => $this->generateUrl('admin_crm_deals_link_contact', ['id' => $deal->id]),
            'contacts' => $deal->linkable,
        ]);
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
