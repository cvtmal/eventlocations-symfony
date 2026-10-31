<?php

declare(strict_types=1);

namespace App\Cms\Command;

/**
 * Rebuild the content-block snapshot (Shared\ReadModel\ContentBlocks::refresh(), #26 P4).
 * ContentBlockWriter dispatches it with DispatchAfterCurrentBusStamp, so it reads the
 * committed rows; rebuilding instead of deleting keeps the footer note on pages that only peek.
 */
final readonly class RefreshContentBlockSnapshot
{
}
