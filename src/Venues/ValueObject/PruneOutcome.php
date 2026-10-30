<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * What app:venues:import --prune did (or would do, in a dry run) with the content venues
 * whose folder is gone.
 */
final readonly class PruneOutcome
{
    /**
     * @param int          $unpublished   venues unpublished
     * @param list<string> $cmsEditedKeys content keys of the venues kept because the CMS edited
     *                                    them since their last import (#24, S8)
     */
    public function __construct(
        public int $unpublished,
        public array $cmsEditedKeys,
    ) {
    }
}
