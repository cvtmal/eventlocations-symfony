<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Form\ContactData;
use App\Crm\Form\ContactType;
use App\Crm\ReadModel\CompanyRecordQuery;
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
 * "Kontakt erfassen" (CRM-2): name, kind, company, e-mail, phone, position and owner; the record page
 * follows. A company page's "+ Hinzufügen" presets the company with `?company=<id>`; an unknown id is
 * ignored. A taken e-mail address is a 422 on the field.
 */
#[IsGranted('ROLE_EDITOR')]
final class ContactCreateController extends AbstractController
{
    /** The handler's field names, as `InvalidPropertyValue::$field`, to the form's. */
    private const array FIELDS = ['last_name' => 'lastName', 'kind' => 'kind', 'company' => 'company', 'owner' => 'owner'];

    public function __construct(
        private readonly CompanyRecordQuery $companies,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/crm/kontakte/neu', name: 'admin_crm_contacts_new', methods: ['GET', 'POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, MessageBusInterface $commandBus, OwnerOptionsQuery $owners): Response
    {
        $data = new ContactData();
        $preset = $request->query->all()['company'] ?? null;
        if (\is_string($preset) && null !== $this->companies->name($preset)) {
            $data->company = $preset;
        }

        // The combobox shows the name of the preset or submitted company, so a re-rendered form keeps it.
        $posted = $request->request->all()['contact'] ?? null;
        $submitted = $request->isMethod('POST') ? (\is_array($posted) ? $posted['company'] ?? null : null) : $data->company;
        $form = $this->createForm(ContactType::class, $data, [
            'owners' => OwnerOptionsQuery::selectable($owners->all()),
            'company_url' => $this->generateUrl('admin_api_crm_companies'),
            'company_label' => \is_string($submitted) && '' !== $submitted ? $this->companies->name($submitted) : null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $id = EntityId::generate();
            try {
                $commandBus->dispatch($data->toCommand($id));
                $this->addFlash('success', CrmFlash::message('create.flash.contact', ['name' => trim($data->firstName.' '.$data->lastName)]));

                return $this->redirectToRoute('admin_crm_contacts_show', ['id' => $id], status: Response::HTTP_SEE_OTHER);
            } catch (HandlerFailedException $failure) {
                [$field, $message] = $this->failure($failure);
                $form->get($field)->addError(new FormError($message));
            }
        }

        return $this->render('admin/crm/contact/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{string, string} the form field and its message
     */
    private function failure(HandlerFailedException $failure): array
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            return match (true) {
                $exception instanceof ContactEmailTaken => ['email', $this->translator->trans('api.email_taken', [], 'crm')],
                $exception instanceof CompanyNotFound => ['company', $this->translator->trans('api.unknown_choice', [], 'crm')],
                $exception instanceof InvalidPropertyValue && isset(self::FIELDS[$exception->field]) => [
                    self::FIELDS[$exception->field],
                    $this->translator->trans($exception->isRequired() ? 'api.required' : 'api.unknown_choice', [], 'crm'),
                ],
                default => throw $failure,
            };
        }

        throw $failure;
    }
}
