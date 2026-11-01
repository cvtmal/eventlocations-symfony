<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\ReadModel\CompanyRecordQuery;
use App\Crm\ReadModel\DealCardQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Deals tab of a company (C:2099): the record's left card, and all its deals where the timeline is.
 */
#[IsGranted('ROLE_EDITOR')]
final class CompanyDealsController extends AbstractController
{
    #[Route('/admin/crm/firmen/{id}/deals', name: 'admin_crm_companies_deals', requirements: ['id' => Requirement::UUID], methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(string $id, CompanyRecordQuery $companies, OwnerOptionsQuery $owners, DealCardQuery $deals): Response
    {
        $company = $companies->find($id) ?? throw $this->createNotFoundException();

        return $this->render('admin/crm/company/deals.html.twig', [
            'company' => $company,
            'owners' => OwnerOptionsQuery::selectable($owners->all(), $company->ownerId),
            'deals' => $deals->allOfCompany($id),
        ]);
    }
}
