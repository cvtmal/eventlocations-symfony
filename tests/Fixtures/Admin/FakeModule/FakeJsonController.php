<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\ValueObject\ValidationErrors;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The JSON-endpoint convention in the smallest form: #22 itself has no /admin/api route.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class FakeJsonController extends AbstractController
{
    #[Route('/admin/api/probe/echo', name: 'admin_api_probe_echo', methods: ['POST'], options: ['unlocalized' => true])]
    public function __invoke(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $payload = $request->toArray();
        $violations = $validator->validate($payload, new Assert\Collection(['name' => [new Assert\NotBlank()]]));

        if (\count($violations) > 0) {
            return new JsonResponse(ValidationErrors::fromViolations($violations)->toArray(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['name' => $payload['name']]);
    }
}
