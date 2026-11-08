<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Command\SuppressCompany;
use App\Crm\Form\OutreachData;
use App\Crm\Form\OutreachType;
use App\Crm\ReadModel\CompanyRecord;
use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ReadModel\OutreachQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SuppressionQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Crm\ValueObject\SuppressionReason;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Location's "Akquise" tab (G11): the outreach fields as one form, and "Widerspruch erfassen", which puts the
 * Location and its addresses on the suppression list for good. A save that changes nothing sends no command (no audit
 * row); an invalid form is shown again with its errors (422).
 */
#[IsGranted('ROLE_EDITOR')]
#[Route('/admin/crm/locations/{id}/akquise', requirements: ['id' => Requirement::UUID], options: [LocalizedRoutes::UNLOCALIZED => true])]
final class CompanyOutreachController extends AbstractController
{
    public const string OBJECTION_TOKEN = 'crm-objection';

    public function __construct(
        private readonly CompanyRecordQuery $companies,
        private readonly OutreachQuery $outreach,
        private readonly SuppressionQuery $suppressions,
        private readonly OwnerOptionsQuery $owners,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('', name: 'admin_crm_companies_outreach', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $company = $this->company($id);
        $current = $this->outreach->of($id) ?? throw $this->createNotFoundException();
        $data = OutreachData::of($current);
        $form = $this->createForm(OutreachType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($data->toOutreach()->equals($current)) {
                $this->addFlash('success', CrmFlash::message('outreach.flash.unchanged'));
            } else {
                $this->commandBus->dispatch($data->toCommand($id));
                $this->addFlash('success', CrmFlash::message('outreach.flash.saved'));
            }

            return $this->redirectToRoute('admin_crm_companies_outreach', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->page($company, $form, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    #[Route('/widerspruch', name: 'admin_crm_companies_outreach_objection', methods: ['POST'])]
    public function objection(string $id, Request $request): RedirectResponse
    {
        $this->company($id);
        $token = $request->request->all()['_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::OBJECTION_TOKEN, $token)) {
            $this->addFlash('error', CrmFlash::message('flash.action_failed'));
        } elseif (true === $this->suppressions->stateOf($id)?->whole) {
            $this->addFlash('success', CrmFlash::message('outreach.flash.already_suppressed'));
        } else {
            $this->commandBus->dispatch(new SuppressCompany($id, SuppressionReason::Objection));
            $this->addFlash('success', CrmFlash::message('outreach.flash.suppressed'));
        }

        return $this->redirectToRoute('admin_crm_companies_outreach', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function page(CompanyRecord $company, FormInterface $form, int $status): Response
    {
        return $this->render('admin/crm/company/outreach.html.twig', [
            'company' => $company,
            'owners' => OwnerOptionsQuery::selectable($this->owners->all(), $company->ownerId),
            'form' => $form,
            'suppression' => $this->suppressions->stateOf($company->id),
            'objection_token' => self::OBJECTION_TOKEN,
        ], new Response(status: $status));
    }

    private function company(string $id): CompanyRecord
    {
        return $this->companies->find($id) ?? throw $this->createNotFoundException();
    }
}
