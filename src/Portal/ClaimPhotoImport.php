<?php

declare(strict_types=1);

namespace App\Portal;

use App\Cms\VenuePhotoUpload;
use App\Shared\Exception\FetchRefused;
use App\Shared\Http\FetchLimits;
use App\Shared\Http\FetchSession;
use App\Shared\Http\SafeFetcher;
use App\Venues\Command\AddVenuePhoto;
use App\Venues\Command\DecidePhotoCandidates;
use App\Venues\ReadModel\PhotoCandidateRow;
use App\Venues\ValueObject\PhotoGrant;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * G6: turns the photo candidates a claimant ticked into photos of the venue, granted on the claimant's declaration.
 * Each file is fetched through `SafeFetcher` (the URLs come from a website, so they are hostile until vetted) and
 * checked like an upload (`VenuePhotoUpload`: type, size, pixels, portrait ratio, JPEG scans) before
 * `AddVenuePhoto` stores it. A file that fails stays a candidate, so the claimant can try again or upload it by
 * hand; the ones the claimant left out are rejected. Nothing is logged that came from the website.
 */
final class ClaimPhotoImport
{
    /** Ticked photos per submit: each is a request of up to 15 seconds, and the claimant waits for all of them. */
    public const int MAX_PICKS = 12;

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly VenuePhotoUpload $upload,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PhotoCandidateRow> $picked
     * @param list<PhotoCandidateRow> $left   the candidates shown but not ticked
     *
     * @return int how many picked photos could not be added
     */
    public function import(int $venueNumber, array $picked, array $left, PhotoGrant $grant): int
    {
        $session = $this->fetcher->open(new FetchLimits(
            maxBytesPerResponse: VenuePhotoUpload::MAX_BYTES,
            maxTotalBytes: self::MAX_PICKS * VenuePhotoUpload::MAX_BYTES,
            maxPages: self::MAX_PICKS,
            totalSeconds: 120.0,
            minDelaySeconds: 0.5,
        ));

        $approved = [];
        foreach (\array_slice($picked, 0, self::MAX_PICKS) as $candidate) {
            if ($this->add($venueNumber, $candidate, $grant, $session)) {
                $approved[] = $candidate->id;
            }
        }

        $this->commandBus->dispatch(new DecidePhotoCandidates(
            $venueNumber,
            $approved,
            array_map(static fn (PhotoCandidateRow $row): string => $row->id, $left),
        ));

        return \count($picked) - \count($approved);
    }

    private function add(int $venueNumber, PhotoCandidateRow $candidate, PhotoGrant $grant, FetchSession $session): bool
    {
        try {
            $result = $session->image($candidate->imageUrl);
        } catch (FetchRefused $refused) {
            $this->logger->info('Claim photo {candidate} of venue {venue} was not fetched ({reason}).', [
                'candidate' => $candidate->id,
                'venue' => $venueNumber,
                'reason' => $refused->reason->value,
            ]);

            return false;
        }

        $path = tempnam(sys_get_temp_dir(), 'claim-photo-');
        if (false === $path) {
            return false;
        }
        try {
            file_put_contents($path, $result->body);
            $file = new File($path);
            $mime = (string) $file->getMimeType();
            $extension = AddVenuePhoto::EXTENSIONS[$mime] ?? null;
            if (null === $extension
                || \count($this->validator->validate($file, $this->upload->rule())) > 0
                || $this->upload->isTooTall($path)
                || $this->upload->hasTooManyScans($path, $mime)) {
                return false;
            }

            $this->commandBus->dispatch(new AddVenuePhoto($venueNumber, null, $path, $extension, $grant));

            return true;
        } catch (HandlerFailedException $failure) {
            $this->logger->info('Claim photo {candidate} of venue {venue} was refused ({exception_class}).', [
                'candidate' => $candidate->id,
                'venue' => $venueNumber,
                'exception_class' => ($failure->getPrevious() ?? $failure)::class,
            ]);

            return false;
        } finally {
            @unlink($path);
        }
    }
}
