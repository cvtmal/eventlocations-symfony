<?php

declare(strict_types=1);

namespace App\Cms\ValueObject;

use App\Cms\ReadModel\VenueHeader;

/**
 * What a host of `Cms\VenueDocumentPages` brings (F11): staff's Dokumente tab and the manager's. `$route` is the list
 * route; the others are named `<route>_edit`, `_up`, `_down` and `_remove`, each with `number` (and `id`); `$csrfId` is the
 * token id of the move and remove forms. The two
 * templates receive the service's context plus `$context($venue)` (the manager's layout needs its submission, plan and
 * offline form). `$admitUpload`, when given, runs once per valid upload before the dispatch and answers null or the
 * `validators` key of a refusal shown on the file field (the manager's upload budget).
 */
final readonly class DocumentPagesSetup
{
    /**
     * @param \Closure(VenueHeader): array<string, mixed> $context
     * @param (\Closure(): ?string)|null                  $admitUpload
     */
    public function __construct(
        public string $route,
        public string $csrfId,
        public string $listTemplate,
        public string $editTemplate,
        public \Closure $context,
        public ?\Closure $admitUpload = null,
    ) {
    }
}
