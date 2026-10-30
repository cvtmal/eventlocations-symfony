<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

/**
 * The page numbers a pagination links to: the first and the last page plus a
 * window of three around the current one (pages 1–3 on page 1, the last three on the last
 * page). A gap of exactly one page shows that page; a longer gap is null ("…").
 *
 *     around(1, 20) = [1, 2, 3, null, 20]
 *     around(5, 20) = [1, null, 4, 5, 6, null, 20]
 */
final readonly class PageLinks
{
    private function __construct()
    {
    }

    /**
     * @return list<int|null>
     */
    public static function around(int $page, int $lastPage): array
    {
        $lastPage = max(1, $lastPage);
        $page = max(1, min($lastPage, $page));

        $start = max(1, min($page - 1, $lastPage - 2));
        $pages = array_unique([1, ...range($start, min($lastPage, $start + 2)), $lastPage]);
        sort($pages);

        $links = [];
        $previous = null;
        foreach ($pages as $number) {
            if (null !== $previous && $number - $previous > 1) {
                $links[] = 2 === $number - $previous ? $previous + 1 : null;
            }
            $links[] = $number;
            $previous = $number;
        }

        return $links;
    }
}
