<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Form\CompanyFilterData;
use App\Crm\Form\CompanyFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ReadModel\CompanyListQuery;
use App\Crm\ReadModel\CompanySort;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Crm\ReadModel\UnlinkedVenuesQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The company list (CRM-6, D20), #24's venue list in Crm: invalid filters give the unfiltered list at
 * 422 with a notice. Four statements: the owners, COUNT, the rows, and the venues left for the bootstrap
 * button.
 */
#[IsGranted('ROLE_EDITOR')]
final class CompanyListController extends AbstractController
{
    #[Route('/admin/crm/firmen', name: 'admin_crm_companies', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, CompanyListQuery $companies, OwnerOptionsQuery $owners, UnlinkedVenuesQuery $unlinkedVenues, FormFactoryInterface $forms): Response
    {
        $data = new CompanyFilterData();
        // An empty name keeps the parameters flat: ?q=…&stage=…
        $form = $forms->createNamed('', CompanyFilterType::class, $data, ['owners' => $owners->all()]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $filter = $form->isSubmitted() && $valid ? $data : new CompanyFilterData();

        $sort = CompanySort::tryFrom(ListQueryString::value($request, 'sort')) ?? CompanySort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;

        return $this->render('admin/crm/company/index.html.twig', [
            'form' => $form,
            'filter' => $filter,
            'list' => $companies->search($form->isSubmitted() && $valid ? $data->toFilter() : new CompanyListFilter(), $sort, $direction, ListQueryString::page($request)),
            'sort' => $sort->value,
            'dir' => $direction->value,
            'unlinked_venues' => $unlinkedVenues->remaining(),
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
