<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Extension\SearchProviderRegistry;
use App\Admin\ValueObject\SearchGroup;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The global search behind the ⌘K palette: `{"groups": [{"label", "results": [{"type", "label", "detail", "url"}]}]}`.
 * It belongs to no module, so its path departs from `/admin/api/<module>/…`.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class SearchController extends AbstractController
{
    private const int MIN_LENGTH = 2;
    private const int MAX_LENGTH = 100;

    public function __construct(
        private readonly SearchProviderRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/api/search', name: 'admin_api_search', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): JsonResponse
    {
        $q = $this->query($request);

        return new JsonResponse([
            'groups' => null === $q ? [] : array_map($this->group(...), $this->registry->search($q)),
        ]);
    }

    /**
     * The usable query, or null. It reads `all()` because `get()` would answer an array `q` with a 400.
     */
    private function query(Request $request): ?string
    {
        $q = $request->query->all()['q'] ?? null;
        if (!\is_string($q) || !mb_check_encoding($q, 'UTF-8') || str_contains($q, "\0")) {
            return null;
        }

        $q = mb_substr(trim($q), 0, self::MAX_LENGTH);

        return mb_strlen($q) < self::MIN_LENGTH ? null : $q;
    }

    /**
     * @return array{label: string, results: list<array{type: string, label: string, detail: ?string, url: string}>}
     */
    private function group(SearchGroup $group): array
    {
        $results = [];
        foreach ($group->results as $result) {
            $results[] = ['type' => $result->type, 'label' => $result->label, 'detail' => $result->detail, 'url' => $result->url];
        }

        return ['label' => $group->label->trans($this->translator), 'results' => $results];
    }
}
