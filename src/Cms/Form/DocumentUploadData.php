<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\Command\AddVenueDocument;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A document upload (#25, CMS-7, D22): one PDF up to 15 MB and its titles. No message names the file:
 * the client's file name may be any bytes.
 */
final class DocumentUploadData
{
    /** The size in `cms.document.file.too_large` too: the validator would print 15000 kB for a file just above. */
    public const string MAX_SIZE = '15M';

    #[Assert\NotNull(message: 'cms.document.file.missing')]
    #[Assert\File(
        maxSize: self::MAX_SIZE,
        mimeTypes: ['application/pdf'],
        disallowEmptyMessage: 'cms.document.file.empty',
        maxSizeMessage: 'cms.document.file.too_large',
        mimeTypesMessage: 'cms.document.file.type',
        uploadIniSizeErrorMessage: 'cms.document.file.too_large',
        uploadFormSizeErrorMessage: 'cms.document.file.too_large',
        uploadErrorMessage: 'cms.document.file.failed',
    )]
    public ?UploadedFile $file = null;

    /** @var array<string, ?string> locale → title */
    public array $titles = [];

    /**
     * `MAX_SIZE` in bytes, as the `File` constraint reads it, for the page's check before a file is sent.
     */
    public static function maxBytes(): int
    {
        return (int) (new Assert\File(maxSize: self::MAX_SIZE))->maxSize;
    }

    /**
     * Call only after the form validated.
     */
    public function toCommand(int $venueNumber): AddVenueDocument
    {
        $file = $this->file ?? throw new \LogicException('Validate the form before building its command.');

        return new AddVenueDocument($venueNumber, $file->getPathname(), array_map(static fn (?string $title): string => $title ?? '', $this->titles));
    }
}
