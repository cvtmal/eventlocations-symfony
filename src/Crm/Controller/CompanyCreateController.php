<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Form\CompanyData;
use App\Crm\Form\CompanyType;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Firma erfassen" (CRM-1), #24's `VenueCreateController` in Crm: name, website, phone, e-mail and
 * owner; the record page follows. The id is generated before dispatch, so the redirect knows it.
 */
#[IsGranted('ROLE_EDITOR')]
final class CompanyCreateController extends AbstractController
{
    /** The handler's field names, as `InvalidPropertyValue::$field`, to the form's. */
    private const array FIELDS = ['name' => 'name', 'owner' => 'owner'];

    #[Route('/admin/crm/firmen/neu', name: 'admin_crm_companies_new', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, MessageBusInterface $commandBus, OwnerOptionsQuery $owners, TranslatorInterface $translator): Response
    {
        $data = new CompanyData();
        $form = $this->createForm(CompanyType::class, $data, ['owners' => OwnerOptionsQuery::selectable($owners->all())]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $id = EntityId::generate();
            try {
                $commandBus->dispatch($data->toCommand($id));
                $this->addFlash('success', CrmFlash::message('create.flash.company', ['name' => (string) $data->name]));

                return $this->redirectToRoute('admin_crm_companies_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
            } catch (HandlerFailedException $failure) {
                $invalid = array_values($failure->getWrappedExceptions(InvalidPropertyValue::class))[0] ?? throw $failure;
                $form->get(self::FIELDS[$invalid->field] ?? throw $failure)->addError(new FormError(
                    $translator->trans($invalid->isRequired() ? 'api.required' : 'api.unknown_choice', [], 'crm'),
                ));
            }
        }

        return $this->render('admin/crm/company/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
