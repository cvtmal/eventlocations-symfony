<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Geo\ReadModel\PlaceLookup;
use App\Geo\ValueObject\Canton;
use App\Geo\ValueObject\PlaceType;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ValueObject\TownText;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "PLZ / Ort" combobox's source (D1, D22), in `ComboboxType`'s answer shape. Each option's value is the
 * text the address card parses (`TownText`, "8706 Meilen"), so picking one fills the free-text field with a
 * value `RelocateVenue` resolves; a ZIP search keeps the ZIP that matched. Cantons are left out: the card has
 * its own canton field. Geo's `PlaceLookup` answers, so the address path makes no external call (D3).
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST', 'PUT', 'PATCH', 'DELETE'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class TownLookupController extends AbstractController
{
    private const int MIN_LENGTH = 2;

    public function __construct(
        private readonly PlaceLookup $places,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/api/cms/towns', name: 'admin_api_cms_towns', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request): JsonResponse
    {
        $q = $this->query($request);
        $options = [];
        foreach (null === $q ? [] : $this->places->suggest($q) as $place) {
            if (PlaceType::Town !== $place->type || null === $place->name || null === $place->zip) {
                continue;
            }
            $text = TownText::format($place->zip, $place->name);
            $options[] = ['value' => $text, 'label' => $text, 'detail' => Canton::from($place->cantonCode)->trans($this->translator)];
        }

        return new JsonResponse(['options' => $options]);
    }

    /**
     * The usable query, or null. It reads `all()` because `get()` would answer an array `q` with a 400; invalid
     * UTF-8 or a NUL would fail in Postgres, and a single character (one ZIP digit) lists too much to help.
     */
    private function query(Request $request): ?string
    {
        $q = $request->query->all()['q'] ?? null;
        if (!\is_string($q) || !mb_check_encoding($q, 'UTF-8') || str_contains($q, "\0")) {
            return null;
        }
        $q = trim($q);

        return mb_strlen($q) < self::MIN_LENGTH ? null : $q;
    }
}
