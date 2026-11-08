<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\Form\VenueFilterData;
use App\Cms\Form\VenueFilterType;
use App\Cms\ReadModel\SortDirection;
use App\Cms\ReadModel\VenueListFilter;
use App\Cms\ReadModel\VenueListQuery;
use App\Cms\ReadModel\VenueSort;
use App\Shared\Translation\LocalizedRoutes;
use App\Venues\ReadModel\TierOptionsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The venue list (CMS-1). Invalid filters give the unfiltered list at 422, as the audit screen does; an
 * unknown sort or direction falls back to the default, a page outside the range is clamped by the query.
 */
#[IsGranted('ROLE_EDITOR')]
final class VenueListController extends AbstractController
{
    #[Route('/admin/locations', name: 'admin_locations', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, VenueListQuery $venues, FormFactoryInterface $forms, TierOptionsQuery $tiers): Response
    {
        $data = new VenueFilterData();
        // An empty name keeps the parameters flat: ?q=…&tier=…
        $form = $forms->createNamed('', VenueFilterType::class, $data);
        $form->handleRequest($request);
        $valid = !$form->isSubmitted() || $form->isValid();

        $query = $request->query->all();
        $sort = VenueSort::tryFrom(self::scalar($query['sort'] ?? null)) ?? VenueSort::DEFAULT;
        $direction = SortDirection::tryFrom(self::scalar($query['dir'] ?? null)) ?? SortDirection::Asc;
        $page = filter_var(self::scalar($query['page'] ?? null), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $this->render('admin/cms/venue/index.html.twig', [
            'form' => $form,
            'filter' => $form->isSubmitted() && $valid ? $data : new VenueFilterData(),
            'list' => $venues->search($form->isSubmitted() && $valid ? $data->toFilter() : new VenueListFilter(), $sort, $direction, false === $page ? 1 : $page),
            'tier_names' => array_flip($tiers->choices()),
            'sort' => $sort->value,
            'dir' => $direction->value,
        ], new Response(status: $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private static function scalar(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
