<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Form\ContactFilterData;
use App\Crm\Form\ContactFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\ContactListFilter;
use App\Crm\ReadModel\ContactListQuery;
use App\Crm\ReadModel\ContactSort;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The contact list (CRM-6, D20): invalid filters give the unfiltered list at 422 with a notice. Three
 * statements: the owners, COUNT, the rows.
 */
#[IsGranted('ROLE_EDITOR')]
final class ContactListController extends AbstractController
{
    #[Route('/admin/crm/kontakte', name: 'admin_crm_contacts', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, ContactListQuery $contacts, OwnerOptionsQuery $owners, FormFactoryInterface $forms): Response
    {
        $data = new ContactFilterData();
        $form = $forms->createNamed('', ContactFilterType::class, $data, ['owners' => $owners->all()]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $filter = $form->isSubmitted() && $valid ? $data : new ContactFilterData();

        $sort = ContactSort::tryFrom(ListQueryString::value($request, 'sort')) ?? ContactSort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;

        return $this->render('admin/crm/contact/index.html.twig', [
            'form' => $form,
            'filter' => $filter,
            'list' => $contacts->search($form->isSubmitted() && $valid ? $data->toFilter() : new ContactListFilter(), $sort, $direction, ListQueryString::page($request)),
            'sort' => $sort->value,
            'dir' => $direction->value,
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
