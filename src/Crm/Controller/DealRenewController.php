<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\RenewDeal;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\DealNotRenewable;
use App\Crm\ValueObject\CrmFlash;
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
 * "Verlängern" (CRM-8, D21), PRG to the new deal's record. A double click is the handler's refusal, a flash on the old
 * record.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealRenewController extends AbstractController
{
    /** The token id of the form, a plain one as the record's other small forms. */
    public const string TOKEN = 'submit';

    #[Route('/admin/crm/deals/{id}/verlaengern', name: 'admin_crm_deals_renew', requirements: ['id' => Requirement::UUID], methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, Request $request, MessageBusInterface $commandBus): Response
    {
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));

            return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        $renewalId = EntityId::generate();
        try {
            $commandBus->dispatch(new RenewDeal($renewalId, $id));
        } catch (HandlerFailedException $failure) {
            if ([] !== $failure->getWrappedExceptions(DealNotFound::class)) {
                throw $this->createNotFoundException();
            }
            if ([] === $failure->getWrappedExceptions(DealNotRenewable::class)) {
                throw $failure;
            }
            $this->addFlash('error', CrmFlash::message('deal.renew.flash.refused'));

            return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', CrmFlash::message('deal.renew.flash.created'));

        return $this->redirectToRoute('admin_crm_deals_show', ['id' => $renewalId], status: Response::HTTP_SEE_OTHER);
    }
}
