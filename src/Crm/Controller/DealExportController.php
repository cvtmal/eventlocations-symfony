<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\CsvStream;
use App\Crm\Form\DealFilterData;
use App\Crm\Form\DealFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\DealCsvRow;
use App\Crm\ReadModel\DealListQuery;
use App\Crm\ReadModel\DealSort;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Crm\ValueObject\Chf;
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
 * The deal list as a CSV (S13, admins only): the list's filters and order, every page. An invalid filter goes back to
 * the list, so its 422 notice stays in one place (FP P14). Amounts are plain numbers, so a spreadsheet can sum them.
 */
#[IsGranted('ROLE_ADMIN')]
final class DealExportController extends AbstractController
{
    private const string ZONE = 'Europe/Zurich';

    #[Route('/admin/crm/deals/export', name: 'admin_crm_deals_export', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        DealListQuery $deals,
        DealStageQuery $stages,
        OwnerOptionsQuery $owners,
        FormFactoryInterface $forms,
        TranslatorInterface $translator,
        ClockInterface $clock,
        LoggerInterface $logger,
    ): Response {
        $data = new DealFilterData();
        $form = $forms->createNamed('', DealFilterType::class, $data, [
            'owners' => $owners->all(),
            'stages' => $stages->codes(),
            'months' => DealFilterType::monthsFor($deals->months(), $request),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && !$form->isValid()) {
            return $this->redirectToRoute('admin_crm_deals', ['ansicht' => 'liste'] + $request->query->all(), Response::HTTP_SEE_OTHER);
        }

        $sort = DealSort::tryFrom(ListQueryString::value($request, 'sort')) ?? DealSort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;

        return CsvStream::response(
            'deals-'.$clock->now()->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d').'.csv',
            [
                $translator->trans('deal.property.name', [], 'crm'),
                $translator->trans('deal.export.company', [], 'crm'),
                $translator->trans('deal.property.venue', [], 'crm'),
                $translator->trans('deal.export.stage', [], 'crm'),
                $translator->trans('deal.export.lost_reason', [], 'crm'),
                $translator->trans('deal.property.tier', [], 'crm'),
                $translator->trans('deal.export.amount', [], 'crm'),
                $translator->trans('deal.property.contract_start', [], 'crm'),
                $translator->trans('deal.property.contract_end', [], 'crm'),
                $translator->trans('deal.property.expected_close', [], 'crm'),
                $translator->trans('deal.property.owner', [], 'crm'),
            ],
            self::cells($deals->csvRows($data->toFilter(), $sort, $direction), $translator),
            $translator->trans('export.failed', [], 'crm'),
            $logger,
        );
    }

    /**
     * @param iterable<DealCsvRow> $rows
     *
     * @return \Generator<list<string|null>>
     */
    private static function cells(iterable $rows, TranslatorInterface $translator): \Generator
    {
        foreach ($rows as $row) {
            yield [
                $row->name,
                $row->companyName,
                $row->venueName,
                $translator->trans('deal.stage.'.$row->stage, [], 'crm'),
                $row->lostReason?->trans($translator),
                $row->tier?->name,
                null === $row->amountRappen ? null : Chf::plain($row->amountRappen),
                $row->contractStartOn?->format('d.m.Y'),
                $row->contractEndOn?->format('d.m.Y'),
                $row->expectedCloseOn?->format('d.m.Y'),
                $row->ownerName,
            ];
        }
    }
}
