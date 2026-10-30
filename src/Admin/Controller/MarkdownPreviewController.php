<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\ValueObject\ValidationErrors;
use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\Translation\LocalizedRoutes;
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
 * The Vorschau tab of a Markdown field: `{"text": "…"}` in, `{"html": "…"}` out. It belongs to no module, so
 * its path departs from `/admin/api/<module>/…`, like the search.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class MarkdownPreviewController extends AbstractController
{
    private const int MAX_LENGTH = 20000;

    #[Route('/admin/api/markdown-preview', name: 'admin_api_markdown_preview', methods: ['POST'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, ValidatorInterface $validator, MarkdownRenderer $renderer): JsonResponse
    {
        // Malformed JSON is a missing field: `toArray()` would answer an HTML 400 the island cannot read.
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        $violations = $validator->validate($payload, new Assert\Collection([
            'text' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Length(max: self::MAX_LENGTH)],
        ]));

        if (\count($violations) > 0) {
            return new JsonResponse(ValidationErrors::fromViolations($violations)->toArray(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        \assert(\is_string($payload['text']));

        return new JsonResponse(['html' => $renderer->render($payload['text'])]);
    }
}
