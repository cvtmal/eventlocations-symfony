<?php

declare(strict_types=1);

namespace App\Cms\Controller;

use App\Cms\ReadModel\ContentBlockStatusQuery;
use App\Shared\Extension\LandingPageSource;
use App\Shared\Translation\LocalizedRoutes;
use App\Shared\ValueObject\LandingPageEntry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The landing pages staff can write texts for (#26, CMS-12): every canton, town and building page with at
 * least one published venue, live or under the threshold. `typ` picks a type (anything else is all), `q`
 * matches the name case-insensitively, a page outside the range is clamped. Two queries: the pages and the
 * newest change per page.
 */
#[IsGranted('ROLE_EDITOR')]
final class LandingPageListController extends AbstractController
{
    public const int PER_PAGE = 50;
    public const array TYPES = ['canton', 'town', 'building', 'event'];

    #[Route('/admin/landingpages', name: 'admin_landing_pages', methods: ['GET'], options: [LocalizedRoutes::UNLOCALIZED => true])]
    public function __invoke(Request $request, LandingPageSource $landingPages, ContentBlockStatusQuery $status): Response
    {
        $query = $request->query->all();
        $type = \in_array($query['typ'] ?? null, self::TYPES, true) ? $query['typ'] : null;
        $search = \is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '';
        $page = filter_var(\is_string($query['page'] ?? null) ? $query['page'] : '', \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $all = $landingPages->pages();
        $matching = '' === $search ? $all : array_values(array_filter(
            $all,
            static fn (LandingPageEntry $entry): bool => str_contains(mb_strtolower($entry->name), mb_strtolower($search)),
        ));
        $counts = ['all' => \count($matching)];
        foreach (self::TYPES as $countedType) {
            $counts[$countedType] = \count(array_filter($matching, static fn (LandingPageEntry $entry): bool => $entry->type === $countedType));
        }
        $shown = null === $type ? $matching : array_values(array_filter($matching, static fn (LandingPageEntry $entry): bool => $entry->type === $type));

        $lastPage = max(1, (int) ceil(\count($shown) / self::PER_PAGE));
        $current = min(false === $page ? 1 : $page, $lastPage);
        $changes = $status->byPrefix('landing.');

        $rows = [];
        foreach (\array_slice($shown, ($current - 1) * self::PER_PAGE, self::PER_PAGE) as $entry) {
            $rows[] = ['entry' => $entry, 'updated_at' => $changes->of('landing.'.$entry->keyPrefix)];
        }

        return $this->render('admin/cms/pages/landing_list.html.twig', [
            'rows' => $rows,
            'type' => $type,
            'q' => $search,
            'counts' => $counts,
            'page' => $current,
            'last_page' => $lastPage,
            'total' => \count($shown),
            'per_page' => self::PER_PAGE,
        ]);
    }
}
