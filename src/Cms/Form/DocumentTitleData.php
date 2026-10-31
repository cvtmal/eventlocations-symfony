<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\ManagedDocument;
use App\Venues\Command\RetitleVenueDocument;

/**
 * A document's titles (#25, CMS-7) with the document's row token (PF2).
 */
final class DocumentTitleData
{
    use CarriesLoadedAt;

    /** @var array<string, ?string> locale → title */
    public array $titles = [];

    public static function from(ManagedDocument $document): self
    {
        $data = new self();
        $data->loadedAt = $document->token;
        $data->titles = $document->titles;

        return $data;
    }

    public function toCommand(int $venueNumber, string $documentId): RetitleVenueDocument
    {
        return new RetitleVenueDocument($venueNumber, $documentId, $this->token(), array_map(static fn (?string $title): string => $title ?? '', $this->titles));
    }
}
