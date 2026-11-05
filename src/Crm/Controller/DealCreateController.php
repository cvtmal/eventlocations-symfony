<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\VenueNotLinked;
use App\Crm\Form\DealData;
use App\Crm\Form\DealPickCompanyType;
use App\Crm\Form\DealType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\DealFormQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ValueObject\CrmFlash;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\Uid\EntityId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Deal erfassen" (CRM-7, N5) in two steps: without `?company=` a GET form picks the Location, because it never
 * changes and the Eintrag depends on it; with it the deal form shows that Location's Eintrag, which the deal takes
 * (#56 D7). Neither step needs its own endpoint.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealCreateController extends AbstractController
{
    /** The handler's field names, as `InvalidPropertyValue::$field`, to the form's. */
    private const array FIELDS = ['name' => 'name', 'owner' => 'owner'];

    #[Route('/admin/crm/deals/neu', name: 'admin_crm_deals_new', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, DealFormQuery $companies, OwnerOptionsQuery $owners, MessageBusInterface $commandBus, FormFactoryInterface $forms, TranslatorInterface $translator): Response
    {
        $companyId = ListQueryString::value($request, 'company');
        $company = '' === $companyId ? null : $companies->company($companyId);
        if (null === $company) {
            return $this->pickCompany($request, $forms, $translator);
        }

        $data = new DealData();
        $form = $this->createForm(DealType::class, $data, ['company' => $company, 'owners' => OwnerOptionsQuery::selectable($owners->all())]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $id = EntityId::generate();
            try {
                $commandBus->dispatch($data->toCommand($id, $company->id, $company->venue?->id));
                $this->addFlash('success', CrmFlash::message('create.flash.deal', ['name' => (string) $data->name]));

                return $this->redirectToRoute('admin_crm_deals_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
            } catch (HandlerFailedException $failure) {
                self::showOnForm($form, $failure, $translator);
            }
        }

        return $this->render('admin/crm/deal/new.html.twig', ['form' => $form, 'company' => $company], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * A `company` in the URL that names no company lands here too, and says so.
     */
    private function pickCompany(Request $request, FormFactoryInterface $forms, TranslatorInterface $translator): Response
    {
        $form = $forms->createNamed('', DealPickCompanyType::class, null, ['company_url' => $this->generateUrl('admin_api_crm_companies')]);
        $refused = $request->query->has('company');
        if ($refused) {
            $form->get('company')->addError(new FormError($translator->trans('crm.deal.company', [], 'validators')));
        }

        return $this->render('admin/crm/deal/pick_company.html.twig', ['form' => $form], new Response(status: $refused ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @throws HandlerFailedException when the handler failed for another reason
     */
    private static function showOnForm(FormInterface $form, HandlerFailedException $failure, TranslatorInterface $translator): void
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            [$field, $key] = match (true) {
                $exception instanceof InvalidPropertyValue => [self::FIELDS[$exception->field] ?? throw $failure, $exception->isRequired() ? 'api.required' : 'api.unknown_choice'],
                $exception instanceof ContractEndsBeforeStart => ['contractEnd', 'api.contract_ends_before_start'],
                // The Eintrag changed between the read and the lock; the form has no Eintrag field (#56 D7).
                $exception instanceof VenueNotLinked => [null, 'api.venue_not_linked'],
                default => throw $failure,
            };
            (null === $field ? $form : $form->get($field))->addError(new FormError($translator->trans($key, [], 'crm')));

            return;
        }

        throw $failure;
    }
}
