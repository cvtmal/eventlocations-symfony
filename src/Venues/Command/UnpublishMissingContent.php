<?php

declare(strict_types=1);

namespace App\Venues\Command;

/**
 * Unpublish the content venues whose folder is gone (app:venues:import --prune). Venues
 * without a content key are never touched, venues the CMS edited only when told; files are
 * kept.
 */
final readonly class UnpublishMissingContent
{
    /**
     * @param list<string> $presentKeys       every folder name of the content set, valid or not
     * @param bool         $dryRun            only count
     * @param bool         $overwriteCmsEdits also unpublish venues the CMS edited since their
     *                                        last import (#24, S8)
     */
    public function __construct(
        public array $presentKeys,
        public bool $dryRun = false,
        public bool $overwriteCmsEdits = false,
    ) {
    }
}
