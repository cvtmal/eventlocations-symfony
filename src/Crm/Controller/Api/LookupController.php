<?php

declare(strict_types=1);

namespace App\Crm\Controller\Api;

use App\Crm\ReadModel\CompanyListQuery;
use App\Crm\ReadModel\VenueLookupQuery;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The CRM comboboxes' sources, in `ComboboxType`'s answer shape `{"options": [{"value", "label", "detail"}]}`:
 * companies (the contact's Firma) and venues ("+ Verknüpfen"). Reads only; the CSRF attribute is the
 * `/admin/api/` convention (`ExtensionPointsTest`) and leaves a GET unchecked.
 */
#[IsGranted('ROLE_EDITOR')]
#[IsCsrfTokenValid('admin_api', tokenKey: 'X-CSRF-Token', methods: ['POST'], tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
final class LookupController extends AbstractController
{
    private const int LIMIT = 10;
    private const int MIN_LENGTH = 2;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/api/crm/companies', name: 'admin_api_crm_companies', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function companies(Request $request, CompanyListQuery $companies): JsonResponse
    {
        $q = $this->query($request);
        $options = [];
        foreach (null === $q ? [] : $companies->quickSearch($q, self::LIMIT) as $company) {
            $options[] = ['value' => $company->id, 'label' => $company->name, 'detail' => $company->stage->trans($this->translator)];
        }

        return new JsonResponse(['options' => $options]);
    }

    #[Route('/admin/api/crm/venues', name: 'admin_api_crm_venues', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function venues(Request $request, VenueLookupQuery $venues): JsonResponse
    {
        $q = $this->query($request, digitsFromOne: true);
        $options = [];
        foreach (null === $q ? [] : $venues->search($q, self::LIMIT) as $venue) {
            $parts = [$this->translator->trans('lookup.venue_number', ['number' => $venue->number], 'crm')];
            if ($venue->place->isKnown()) {
                $parts[] = $venue->place->trans($this->translator);
            }
            if (null !== $venue->companyName) {
                $parts[] = $this->translator->trans('lookup.venue_company', ['company' => $venue->companyName], 'crm');
            }
            $options[] = ['value' => $venue->id, 'label' => $venue->name, 'detail' => implode(' · ', $parts)];
        }

        return new JsonResponse(['options' => $options]);
    }

    /**
     * The usable query, or null (#24's `TownOptions::query()`): `all()`, because `get()` answers an array
     * with a 400; invalid UTF-8 or a NUL would fail in Postgres; one character lists too much to help,
     * except a venue number.
     */
    private function query(Request $request, bool $digitsFromOne = false): ?string
    {
        $q = $request->query->all()['q'] ?? null;
        if (!\is_string($q) || !mb_check_encoding($q, 'UTF-8') || str_contains($q, "\0")) {
            return null;
        }
        $q = trim($q);
        if ($digitsFromOne && 1 === preg_match('/\A\d+\z/', $q)) {
            return $q;
        }

        return mb_strlen($q) < self::MIN_LENGTH ? null : $q;
    }
}
