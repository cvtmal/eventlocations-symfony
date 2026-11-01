<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Crm\Command\ChangeDealProperty;
use App\Crm\CrmApi;
use App\Crm\Form\PropertyConstraints;
use App\Crm\ReadModel\RecordPropertyQuery;
use App\Crm\ValueObject\DealProperty;
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
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * One inline edit of a deal (D17-a): `{"field", "value", "expected"}` in, `{"value", "display", "draft"}` out, because a
 * stored amount or date is not what the row shows (N4), and an amount is not what it edits either. An unchanged value answers without a dispatch (P1).
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['PATCH'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class DealPropertyController extends AbstractController
{
    #[Route('/admin/api/crm/deals/{id}', name: 'admin_api_crm_deal_property', requirements: ['id' => Requirement::UUID], methods: ['PATCH'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        string $id,
        Request $request,
        CrmApi $api,
        ValidatorInterface $validator,
        RecordPropertyQuery $properties,
        MessageBusInterface $commandBus,
    ): JsonResponse {
        $edit = $api->propertyEdit($request, array_map(static fn (DealProperty $property): string => $property->value, DealProperty::cases()));
        if ($edit instanceof JsonResponse) {
            return $edit;
        }

        $property = DealProperty::from($edit->field);
        $violations = $validator->startContext()
            ->atPath('value')->validate(PropertyValue::canonical($edit->value), new Assert\Sequentially(PropertyConstraints::deal($property)))
            ->getViolations();
        if (\count($violations) > 0) {
            return $api->invalid($violations);
        }

        $value = $property->canonical($edit->value);
        $current = $properties->deal($id, $property);
        if (null === $current) {
            return $api->notFound();
        }
        if ($value === $current->value) {
            return self::answer($property, $current->value);
        }

        try {
            $stored = $commandBus->dispatch(new ChangeDealProperty($id, $property, $edit->value, $edit->expected))
                ->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failure) {
            return $api->failure($failure);
        }
        \assert(null === $stored || \is_string($stored));

        return self::answer($property, $stored);
    }

    private static function answer(DealProperty $property, ?string $stored): JsonResponse
    {
        return new JsonResponse(['value' => $stored, 'display' => $property->display($stored), 'draft' => $property->draft($stored)]);
    }
}
