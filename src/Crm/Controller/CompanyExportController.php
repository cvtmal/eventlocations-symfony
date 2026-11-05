<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\CsvStream;
use App\Crm\Form\CompanyFilterData;
use App\Crm\Form\CompanyFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\CompanyCsvRow;
use App\Crm\ReadModel\CompanyListQuery;
use App\Crm\ReadModel\CompanySort;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Shared\Translation\LocalizedRoutes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The company list as a CSV (CRM-6, D20, admins only): the list's filters and order, every page. An
 * invalid filter goes back to the list, so its 422 notice stays in one place (P14).
 */
#[IsGranted('ROLE_ADMIN')]
final class CompanyExportController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    #[Route('/admin/crm/locations/export', name: 'admin_crm_companies_export', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        CompanyListQuery $companies,
        OwnerOptionsQuery $owners,
        FormFactoryInterface $forms,
        TranslatorInterface $translator,
        ClockInterface $clock,
        LoggerInterface $logger,
    ): Response {
        $data = new CompanyFilterData();
        $form = $forms->createNamed('', CompanyFilterType::class, $data, ['owners' => $owners->all()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            return $this->redirectToRoute('admin_crm_companies', $request->query->all(), Response::HTTP_SEE_OTHER);
        }

        $sort = CompanySort::tryFrom(ListQueryString::value($request, 'sort')) ?? CompanySort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;
        $zone = new \DateTimeZone(self::ZONE);

        return CsvStream::response(
            'locations-'.$clock->now()->setTimezone($zone)->format('Y-m-d').'.csv',
            [
                $translator->trans('company.property.name', [], 'crm'),
                $translator->trans('company.property.legal_name', [], 'crm'),
                $translator->trans('company.property.website', [], 'crm'),
                $translator->trans('company.property.phone', [], 'crm'),
                $translator->trans('company.property.email', [], 'crm'),
                $translator->trans('company.property.address', [], 'crm'),
                $translator->trans('company.property.stage', [], 'crm'),
                $translator->trans('company.property.owner', [], 'crm'),
                $translator->trans('list.companies.column.venues', [], 'crm'),
                $translator->trans('export.origin', [], 'crm'),
                $translator->trans('list.companies.column.created', [], 'crm'),
            ],
            self::cells($companies->csvRows($data->toFilter(), $sort, $direction), $translator, $zone),
            $translator->trans('export.failed', [], 'crm'),
            $logger,
        );
    }

    /**
     * @param iterable<CompanyCsvRow> $rows
     *
     * @return \Generator<list<string|null>>
     */
    private static function cells(iterable $rows, TranslatorInterface $translator, \DateTimeZone $zone): \Generator
    {
        foreach ($rows as $row) {
            yield [
                $row->name,
                $row->legalName,
                $row->website,
                $row->phone,
                $row->email,
                $row->address,
                $row->stage->trans($translator),
                $row->ownerName,
                (string) $row->venueName,
                $row->origin->trans($translator),
                $row->createdAt->setTimezone($zone)->format('d.m.Y'),
            ];
        }
    }
}
