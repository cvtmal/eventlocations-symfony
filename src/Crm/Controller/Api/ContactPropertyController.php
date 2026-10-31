<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Admin\Security\AdminIdentity;
use App\Crm\Command\ChangeContactProperty;
use App\Crm\CrmApi;
use App\Crm\Form\PropertyConstraints;
use App\Crm\ReadModel\RecordPropertyQuery;
use App\Crm\ValueObject\ContactProperty;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * One inline edit of a contact (D10): `{"field", "value", "expected"}` in, `{"value": canonical}` out.
 * An unchanged value answers without a dispatch, so it leaves no audit row (P1).
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['PATCH'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class ContactPropertyController extends AbstractController
{
    #[Route('/admin/api/crm/contacts/{id}', name: 'admin_api_crm_contact_property', requirements: ['id' => Requirement::UUID], methods: ['PATCH'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        string $id,
        Request $request,
        #[CurrentUser] AdminIdentity $user,
        CrmApi $api,
        ValidatorInterface $validator,
        RecordPropertyQuery $properties,
        MessageBusInterface $commandBus,
    ): JsonResponse {
        $edit = $api->propertyEdit($request, array_map(static fn (ContactProperty $property): string => $property->value, ContactProperty::cases()));
        if ($edit instanceof JsonResponse) {
            return $edit;
        }

        $property = ContactProperty::from($edit->field);
        $value = PropertyValue::canonical($edit->value, ContactProperty::Email === $property);
        $violations = $validator->startContext()
            ->atPath('value')->validate($value, new Assert\Sequentially(PropertyConstraints::contact($property)))
            ->getViolations();
        if (\count($violations) > 0) {
            return $api->invalid($violations);
        }

        $current = $properties->contact($id, $property);
        if (null === $current) {
            return $api->notFound();
        }
        if ($value === $current->value) {
            return new JsonResponse(['value' => $current->value]);
        }

        try {
            $stored = $commandBus->dispatch(new ChangeContactProperty($id, $property, $edit->value, $edit->expected, $user->id()))
                ->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            return $api->failure($failure);
        }
        \assert(null === $stored || \is_string($stored));

        return new JsonResponse(['value' => $stored]);
    }
}
