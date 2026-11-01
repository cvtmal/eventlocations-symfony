<?php

declare(strict_types=1);

namespace App\Crm\Controller;

use App\Crm\DealBoardProps;
use App\Crm\Form\DealFilterData;
use App\Crm\Form\DealFilterType;
use App\Crm\Form\ListQueryString;
use App\Crm\ReadModel\DealBoardQuery;
use App\Crm\ReadModel\DealFilter;
use App\Crm\ReadModel\DealListQuery;
use App\Crm\ReadModel\DealSort;
use App\Crm\ReadModel\DealStageQuery;
use App\Crm\ReadModel\DealStageView;
use App\Crm\ReadModel\OwnerOptionsQuery;
use App\Crm\ReadModel\SortDirection;
use App\Shared\Translation\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The deals (D12): the board, or with `?ansicht=liste` #24's server list. Filters are GET parameters reloading the page,
 * so a saved view is a bookmark; invalid ones give the unfiltered page at 422 with a notice. Both views share the form's
 * three statements (owners, stages, months); the list adds COUNT and the rows, the board its cards and totals.
 */
#[IsGranted('ROLE_EDITOR')]
final class DealListController extends AbstractController
{
    private const string LIST_VIEW = 'liste';

    #[Route('/admin/crm/deals', name: 'admin_crm_deals', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(
        Request $request,
        DealListQuery $deals,
        DealBoardQuery $boards,
        DealBoardProps $props,
        DealStageQuery $stages,
        OwnerOptionsQuery $owners,
        FormFactoryInterface $forms,
    ): Response {
        $columns = $stages->all();
        $data = new DealFilterData();
        // An empty name keeps the parameters flat: ?owner=…&abschluss=…
        $form = $forms->createNamed('', DealFilterType::class, $data, [
            'owners' => $owners->all(),
            'stages' => array_map(static fn (DealStageView $stage): string => $stage->code, $columns),
            'months' => DealFilterType::monthsFor($deals->months(), $request),
        ]);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();
        $response = new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
        $filters = $form->isSubmitted() && $valid ? $data : new DealFilterData();

        if (self::LIST_VIEW === ListQueryString::value($request, 'ansicht')) {
            return $this->renderList($request, $deals, $form, $filters->toFilter(), $response);
        }

        $filter = $filters->toBoardFilter();
        $board = $boards->board($columns, $filter);

        return $this->render('admin/crm/deal/board.html.twig', [
            'form' => $form,
            'board' => $board,
            'props' => $props->of($board, $filter),
            'open_figure' => $props->openFigure($board->openSumRappen, $board->openCount),
            'query' => self::viewQuery($filter),
        ], $response);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function renderList(Request $request, DealListQuery $deals, FormInterface $form, DealFilter $filter, Response $response): Response
    {
        $sort = DealSort::tryFrom(ListQueryString::value($request, 'sort')) ?? DealSort::DEFAULT;
        $direction = SortDirection::tryFrom(ListQueryString::value($request, 'dir')) ?? SortDirection::Asc;

        return $this->render('admin/crm/deal/list.html.twig', [
            'form' => $form,
            'filter' => $filter,
            'list' => $deals->search($filter, $sort, $direction, ListQueryString::page($request)),
            'sort' => $sort->value,
            'dir' => $direction->value,
            'query' => self::viewQuery($filter),
        ], $response);
    }

    /**
     * What a switch between board and list keeps.
     *
     * @return array<string, string>
     */
    private static function viewQuery(DealFilter $filter): array
    {
        return array_filter(['owner' => $filter->owner, 'abschluss' => $filter->month], static fn (?string $value): bool => null !== $value);
    }
}
