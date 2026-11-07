<?php

declare(strict_types=1);

namespace App\Venues\CommandHandler;

use App\Geo\Entity\Canton;
use App\Geo\Entity\Town;
use App\Media\MediaPublisher;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Command\ImportVenueContent;
use App\Venues\Command\PurgeVenueCache;
use App\Venues\Command\RemoveVenueFiles;
use App\Venues\ContentFormat;
use App\Venues\ContentReader;
use App\Venues\DocumentPipeline;
use App\Venues\Entity\Room;
use App\Venues\Entity\RoomTranslation;
use App\Venues\Entity\TaxonomyTerm;
use App\Venues\Entity\Venue;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenueDocumentTranslation;
use App\Venues\Entity\VenuePhoto;
use App\Venues\Entity\VenuePhotoTranslation;
use App\Venues\Entity\VenueTerm;
use App\Venues\Entity\VenueTranslation;
use App\Venues\Exception\InvalidContent;
use App\Venues\Exception\UnresolvedTown;
use App\Venues\PhotoGrants;
use App\Venues\PhotoPipeline;
use App\Venues\PublishedMedia;
use App\Venues\Repository\TaxonomyTermRepository;
use App\Venues\Repository\TierRepository;
use App\Venues\Repository\VenueRepository;
use App\Venues\RoomSummary;
use App\Venues\TownResolver;
use App\Venues\ValueObject\Content\PhotoContent;
use App\Venues\ValueObject\Content\RoomContent;
use App\Venues\ValueObject\Content\VenueContent;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\PhotoGrant;
use App\Venues\ValueObject\Seating;
use App\Venues\ValueObject\StagedPhoto;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\VenueImportOutcome;
use App\Venues\ValueObject\VenueStatus;
use App\Venues\VenueSlugs;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Imports one content folder into its venue (#9, docs/content-format.md).
 *
 * 1. Read and validate the folder; a problem is a failed outcome, nothing is written.
 * 2. The guard (#24, S8). Outside a dry run the existing venue's row is locked first, so a
 *    staff save cannot slip in between the check and the write (it waits, then is stale).
 *    A venue the CMS deleted (venue_deleted_content_keys) or edited since its last import
 *    (cms_edited_at set) is reported as cms_edited and left alone, also when forced, unless
 *    the import is told to overwrite CMS edits. Then an existing venue with the same content
 *    hash is unchanged (skipped, no I/O) unless forced, or unless overwriting a CMS edit.
 * 3. Resolve the town (ZIP + name, optionally canton) when the folder names one; none or
 *    several is a failed outcome. Without a town the canton comes from address.canton, if any.
 * 4. A dry run stops here with the would-be counts.
 * 5. New photos: store the original content-addressed under MEDIA_ROOT and render the
 *    variants into a staging directory, all before the first DB write.
 * 6. Write: the venue's fields; translations and slugs (old slug to venue_slug_history);
 *    terms and every translation set replaced; rooms, photos and
 *    documents kept when their identity matches (room key; room key + original; original)
 *    and moved in two flushes because their position uniques are not deferrable.
 * 7. Publish the new variants and PDFs; files of removed rows are deleted by RemoveVenueFiles
 *    and the venue's pages are purged from the HTTP cache (PurgeVenueCache), both after the
 *    commit.
 *
 * A failure in the write phase removes the files this call published, clears the
 * EntityManager (when still open) and rethrows, so the transaction rolls back.
 *
 * One write path (S8): the rules the CMS (#24/#25) shares live in Venues services this
 * handler composes, and the CMS handlers compose the same ones: VenueSlugs (slug + history),
 * TownResolver, RoomSummary, PhotoPipeline, DocumentPipeline and PublishedMedia; after the
 * commit RemoveVenueFiles and PurgeVenueCache. What stays here is import-specific: matching
 * the content against the venue's rows, moving positions, replacing the owned sets and the
 * outcome with its JSON-pointer messages.
 */
final class ImportVenueContentHandler implements CommandHandlerInterface
{
    private const int POSITION_OFFSET = 10000;

    public function __construct(
        private readonly ContentReader $reader,
        private readonly VenueRepository $venues,
        private readonly TaxonomyTermRepository $terms,
        private readonly TierRepository $tiers,
        private readonly TownResolver $towns,
        private readonly EntityManagerInterface $em,
        private readonly VenueSlugs $slugs,
        private readonly PhotoPipeline $photos,
        private readonly PhotoGrants $grants,
        private readonly DocumentPipeline $documents,
        private readonly MediaPublisher $publisher,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(ImportVenueContent $command): VenueImportOutcome
    {
        try {
            $content = $this->reader->read($command->folder);
        } catch (InvalidContent $exception) {
            return VenueImportOutcome::failed($command->key, $command->dryRun, $exception->messages());
        }

        $venue = $this->venues->findOneByContentKey($content->key);
        if (null !== $venue && !$command->dryRun) {
            // A staff save cannot pass the guard unseen: it waits for this row, then is stale.
            $this->em->refresh($venue, LockMode::PESSIMISTIC_WRITE);
        }
        if (null === $venue && !$command->overwriteCmsEdits && $this->venues->isContentKeyDeleted($content->key)) {
            return VenueImportOutcome::cmsEdited($content->key, $command->dryRun);
        }
        $edited = null !== $venue?->cmsEditedAt();
        if (null !== $venue && $venue->contentHash() === $content->contentHash && !$command->force && !($command->overwriteCmsEdits && $edited)) {
            return VenueImportOutcome::unchanged($content->key, $command->dryRun);
        }
        if ($edited && !$command->overwriteCmsEdits) {
            return VenueImportOutcome::cmsEdited($content->key, $command->dryRun);
        }

        try {
            $town = null !== $content->zip && null !== $content->townName
                ? $this->towns->resolve($content->zip, $content->townName, $content->cantonCode)
                : null;
        } catch (UnresolvedTown $exception) {
            return VenueImportOutcome::failed($content->key, $command->dryRun, [
                $exception->isAmbiguous()
                    ? \sprintf('/address: ambiguous town "%s" (%d towns); add address.canton', $exception->place(), $exception->townCount())
                    : \sprintf('/address: unknown town "%s"', $exception->place()),
            ]);
        }

        $existing = $this->existingRows($venue);
        $photoPlan = self::planPhotos($content, $existing['photos']);
        $documentPlan = self::planDocuments($content, $existing['documents']);

        if ($command->dryRun) {
            return null === $venue
                ? VenueImportOutcome::created($content->key, true, \count($photoPlan['new']), \count($documentPlan['new']))
                : VenueImportOutcome::updated($content->key, true, \count($photoPlan['new']), \count($photoPlan['removed']), \count($documentPlan['new']), \count($documentPlan['removed']));
        }

        $staging = $this->photos->openStaging();
        try {
            $staged = $this->stage($content, $photoPlan['new'], $documentPlan['new'], $staging);

            return $this->write($content, $venue, $town, $existing, $photoPlan, $documentPlan, $staged);
        } finally {
            $this->photos->discardStaging($staging);
        }
    }

    /**
     * Stores the new originals and renders the new photos' variants (step 5).
     *
     * @param array<string, PhotoContent> $newPhotos    by identity
     * @param array<string, string>       $newDocuments absolute source by original path
     *
     * @return array<string, StagedPhoto> by original path
     */
    private function stage(VenueContent $content, array $newPhotos, array $newDocuments, string $staging): array
    {
        $staged = [];
        foreach ($newPhotos as $photo) {
            $original = ContentFormat::originalPath($content->key, $photo->sha256, $photo->extension);
            if (isset($staged[$original])) {
                continue; // the same file in the gallery and in a room: rendered once
            }
            $staged[$original] = $this->photos->stage($photo->absolutePath, $original, $staging.'/'.\count($staged));
        }
        foreach ($newDocuments as $original => $source) {
            $this->documents->store($source, $original);
        }

        return $staged;
    }

    /**
     * @param array{new: array<string, PhotoContent>, removed: list<VenuePhoto>, retained: array<string, VenuePhoto>}                                    $photoPlan
     * @param array{new: array<string, string>, removed: list<VenueDocument>, retained: array<string, VenueDocument>}                                    $documentPlan
     * @param array<string, StagedPhoto>                                                                                                                 $staged       by original path
     * @param array{rooms: array<string, Room>, photos: list<VenuePhoto>, documents: list<VenueDocument>, translations: array<string, VenueTranslation>} $existing
     */
    private function write(
        VenueContent $content,
        ?Venue $venue,
        ?Town $town,
        array $existing,
        array $photoPlan,
        array $documentPlan,
        array $staged,
    ): VenueImportOutcome {
        $isNew = null === $venue;
        $published = new PublishedMedia();

        try {
            $oldName = $venue?->name();
            $venue = $this->applyVenueFields($content, $venue, $town);

            // First flush: a new venue gets its number; removed rows go and retained ones
            // move out of the way (the position uniques are not deferrable).
            $roomsByKey = $existing['rooms'];
            $removedRooms = array_diff_key($roomsByKey, array_flip(array_map(static fn (RoomContent $room): string => $room->key, $content->rooms)));
            foreach ($photoPlan['removed'] as $photo) {
                $this->grants->releasePhoto($photo, Grantor::system('import'));
                $this->em->remove($photo);
            }
            foreach ($documentPlan['removed'] as $document) {
                $this->em->remove($document);
            }
            foreach ($removedRooms as $room) {
                $this->em->remove($room);
            }
            foreach (array_diff_key($roomsByKey, $removedRooms) as $room) {
                $room->moveTo($room->position() + self::POSITION_OFFSET);
            }
            foreach ($photoPlan['retained'] as $photo) {
                $photo->moveTo($photo->position() + self::POSITION_OFFSET);
            }
            foreach ($documentPlan['retained'] as $document) {
                $document->moveTo($document->position() + self::POSITION_OFFSET);
            }
            if (!$isNew) {
                $this->replaceOwnedSets($venue, array_diff_key($roomsByKey, $removedRooms), $photoPlan['retained'], $documentPlan['retained']);
            }
            $this->em->flush();
            $number = $venue->number();
            if ($isNew) {
                $published->newVenue($number);
            }

            $this->applyTranslations($content, $venue, $town, $isNew ? null : $oldName, $existing['translations']);
            $this->applyTerms($content, $venue);

            $rooms = [];
            foreach ($content->rooms as $index => $roomContent) {
                $room = $roomsByKey[$roomContent->key] ?? null;
                if (null === $room) {
                    $room = new Room($venue, $index + 1, $roomContent->capacityMin, $roomContent->capacityMax, $roomContent->areaM2, $roomContent->heightM, $roomContent->floorMaterial, $roomContent->key);
                    $this->em->persist($room);
                }
                foreach (Seating::cases() as $seating) {
                    $range = $roomContent->seating[$seating->value] ?? ['min' => null, 'max' => null];
                    $room->offerSeating($seating, $range['min'], $range['max']);
                }
                if (isset($roomsByKey[$roomContent->key])) {
                    // After the layouts, so a room whose layouts all went takes the file's range again (F4).
                    $room->moveTo($index + 1);
                    $room->reshape($roomContent->capacityMin, $roomContent->capacityMax, $roomContent->areaM2, $roomContent->heightM, $roomContent->floorMaterial);
                }
                foreach ($roomContent->translations as $locale => $text) {
                    $this->em->persist(new RoomTranslation($room, $locale, $text['name'], $text['description']));
                }
                $rooms[$roomContent->key] = $room;
            }

            $newPhotos = [];
            $lists = [[null, $content->photos]];
            foreach ($content->rooms as $roomContent) {
                $lists[] = [$roomContent->key, $roomContent->photos];
            }
            foreach ($lists as [$roomKey, $photos]) {
                foreach ($photos as $index => $photoContent) {
                    $original = ContentFormat::originalPath($content->key, $photoContent->sha256, $photoContent->extension);
                    $photo = $photoPlan['retained'][self::photoIdentity($roomKey, $original)] ?? null;
                    if (null === $photo) {
                        $photo = $this->photos->add($venue, null === $roomKey ? null : $rooms[$roomKey], $index + 1, $staged[$original]);
                        // The folder is the owner's own content: staff attest the rights (G1).
                        $this->grants->issue($photo, new PhotoGrant(Grantor::system('import'), GrantEvidence::StaffAttestation));
                        $newPhotos[] = [$photo, $staged[$original]];
                    } else {
                        $photo->moveTo($index + 1);
                    }
                    foreach ($photoContent->alt as $locale => $alt) {
                        $this->em->persist(new VenuePhotoTranslation($photo, $locale, $alt));
                    }
                }
            }

            $newDocuments = [];
            $remaining = [];
            foreach ($content->documents as $index => $documentContent) {
                $original = ContentFormat::originalPath($content->key, $documentContent->sha256, 'pdf');
                $document = $documentPlan['retained'][$original] ?? null;
                if (null === $document) {
                    $document = $this->documents->add($venue, $index + 1, $original, $documentContent->sizeBytes);
                    $newDocuments[] = $document;
                } else {
                    $document->moveTo($index + 1);
                }
                foreach ($documentContent->title as $locale => $title) {
                    $this->em->persist(new VenueDocumentTranslation($document, $locale, $title));
                }
                $remaining[$original] = true;
            }
            foreach ($photoPlan['retained'] as $photo) {
                $remaining[$photo->originalPath()] = true;
            }
            foreach (array_keys($staged) as $original) {
                $remaining[$original] = true;
            }

            RoomSummary::apply($venue, array_values($rooms));
            $venue->recordContentHash($content->contentHash);
            if ($isNew) {
                $this->venues->forgetDeletedContentKey($content->key); // recreated by an overwrite
            }
            $this->em->flush();

            foreach ($newPhotos as [$photo, $stagedPhoto]) {
                $this->photos->publish($photo, $stagedPhoto, $published);
            }
            foreach ($newDocuments as $document) {
                $this->documents->publish($document, $published);
            }

            $removedOriginals = [];
            foreach ([...$photoPlan['removed'], ...$documentPlan['removed']] as $row) {
                $path = $row instanceof VenuePhoto ? $row->originalPath() : $row->path();
                if (!isset($remaining[$path])) {
                    $removedOriginals[$path] = $path;
                }
            }
            $removedPhotoIds = array_map(static fn (VenuePhoto $photo): string => $photo->id(), $photoPlan['removed']);
            $removedDocumentIds = array_map(static fn (VenueDocument $document): string => $document->id(), $documentPlan['removed']);
            if ([] !== $removedPhotoIds || [] !== $removedDocumentIds || [] !== $removedOriginals) {
                $this->commandBus->dispatch(new RemoveVenueFiles($number, $removedPhotoIds, $removedDocumentIds, array_values($removedOriginals)), [new DispatchAfterCurrentBusStamp()]);
            }
            $this->commandBus->dispatch(new PurgeVenueCache($number), [new DispatchAfterCurrentBusStamp()]);

            $this->em->clear();
        } catch (\Throwable $exception) {
            $published->revert($this->publisher);
            if ($this->em->isOpen()) {
                $this->em->clear();
            }

            throw $exception;
        }

        return $isNew
            ? VenueImportOutcome::created($content->key, false, \count($photoPlan['new']), \count($documentPlan['new']))
            : VenueImportOutcome::updated($content->key, false, \count($photoPlan['new']), \count($photoPlan['removed']), \count($documentPlan['new']), \count($documentPlan['removed']));
    }

    private function applyVenueFields(VenueContent $content, ?Venue $venue, ?Town $town): Venue
    {
        // The code passed the schema's enum, so the reference can never miss the FK.
        $canton = null === $town && null !== $content->cantonCode ? $this->em->getReference(Canton::class, $content->cantonCode) : null;

        if (null === $venue) {
            $venue = new Venue($content->name, $this->tiers->free(), $content->status, $content->capacityMax, $content->capacityMin, $town, $content->zip, $content->street, $content->lat, $content->lng, $content->key, $canton);
            $this->em->persist($venue);
        } else {
            $venue->rename($content->name);
            VenueStatus::Published === $content->status ? $venue->publish() : $venue->unpublish();
            $venue->changeCapacity($content->capacityMax, $content->capacityMin);
            // A folder's coordinates are the venue's own point, so a town centre set in the CMS goes.
            $venue->relocate($town, $content->zip, $content->street, $content->lat, $content->lng, $canton, coordinatesFromTown: false);
        }
        $venue->changeContact($content->phone, $content->inquiryEmail, $content->website, $content->contactPerson);
        $venue->changeDistances($content->stationKm, $content->stationName, $content->highwayKm, $content->highwayName, $content->airportKm, $content->airportName);
        $venue->answerFaq($content->daylight);
        $venue->changePriceLevel($content->priceLevel);
        $venue->changeSeatingCapacity($content->capacitySeated, $content->capacityStanding);
        $venue->changeInstagram($content->instagram);

        return $venue;
    }

    /**
     * Deletes the replaced-as-a-whole rows of an existing venue: terms, and the translations
     * of the rooms, photos and documents it keeps. The retired highlights and reviews (#53) are
     * left alone: the import neither reads nor deletes them until the drop migration.
     *
     * @param array<string, Room>          $rooms
     * @param array<string, VenuePhoto>    $photos
     * @param array<string, VenueDocument> $documents
     */
    private function replaceOwnedSets(Venue $venue, array $rooms, array $photos, array $documents): void
    {
        $this->em->createQuery(\sprintf('DELETE FROM %s x WHERE x.venue = :venue', VenueTerm::class))->setParameter('venue', $venue)->execute();
        foreach ([[RoomTranslation::class, 'room', $rooms], [VenuePhotoTranslation::class, 'photo', $photos], [VenueDocumentTranslation::class, 'document', $documents]] as [$class, $field, $owners]) {
            if ([] !== $owners) {
                $this->em->createQuery(\sprintf('DELETE FROM %s x WHERE x.%s IN (:owners)', $class, $field))->setParameter('owners', array_values($owners))->execute();
            }
        }
    }

    /**
     * Updates or adds one VenueTranslation per locale and removes the locales no longer in
     * the content; the slug comes from VenueSlugs (changes only with the name, with history).
     *
     * @param array<string, VenueTranslation> $rows the venue's current rows by locale
     */
    private function applyTranslations(VenueContent $content, Venue $venue, ?Town $town, ?string $oldName, array $rows): void
    {
        foreach ($content->translations as $locale => $text) {
            $row = $rows[$locale] ?? null;
            $slug = $this->slugs->assign($venue, $locale, $content->name, $town, $row, $oldName !== $content->name);

            if (null === $row) {
                $row = new VenueTranslation($venue, $locale, $slug, $text['summary'], $text['description'], $text['metaDescription'], $text['parkingText']);
                $this->em->persist($row);
            } else {
                $row->revise($slug, $text['summary'], $text['description'], $text['metaDescription'], $text['parkingText']);
            }
            $row->describeEvent($text['ambience'], $text['capacityNote'], $text['openingHours']);
            $row->changeRegion($text['region']);
        }
        foreach (array_diff_key($rows, $content->translations) as $row) {
            $this->em->remove($row);
        }
    }

    private function applyTerms(VenueContent $content, Venue $venue): void
    {
        foreach ($content->terms as $group => $codes) {
            $byCode = [];
            foreach ($this->terms->byGroup(TermGroup::from($group)) as $term) {
                $byCode[$term->code()] = $term;
            }
            foreach ($codes as $code) {
                $term = $byCode[$code] ?? null;
                \assert($term instanceof TaxonomyTerm, 'The schema pins the codes to taxonomy_terms.');
                $this->em->persist(new VenueTerm($venue, $term, ($content->mainTerms[$group] ?? null) === $code));
            }
        }
    }

    /**
     * The venue's current rows the import may keep (none for a new venue).
     *
     * @return array{rooms: array<string, Room>, photos: list<VenuePhoto>, documents: list<VenueDocument>, translations: array<string, VenueTranslation>}
     */
    private function existingRows(?Venue $venue): array
    {
        if (null === $venue) {
            return ['rooms' => [], 'photos' => [], 'documents' => [], 'translations' => []];
        }
        $rooms = [];
        foreach ($this->em->getRepository(Room::class)->findBy(['venue' => $venue]) as $room) {
            // A room without a key (not written by the import) matches nothing and is removed.
            $rooms[$room->contentKey() ?? '#'.$room->id()] = $room;
        }
        $translations = [];
        foreach ($this->em->getRepository(VenueTranslation::class)->findBy(['venue' => $venue]) as $translation) {
            $translations[$translation->locale()] = $translation;
        }

        return [
            'rooms' => $rooms,
            'photos' => $this->em->getRepository(VenuePhoto::class)->findBy(['venue' => $venue]),
            'documents' => $this->em->getRepository(VenueDocument::class)->findBy(['venue' => $venue]),
            'translations' => $translations,
        ];
    }

    /**
     * Matches the content's photos against the venue's by "<room key>|<original path>": a
     * match keeps its row, id and variants; the rest is new or removed. A room without a key
     * goes with this import, so a photo in it matches nothing, not even the folder's gallery.
     * #25 can leave two rows of one original in one scope (RemoveRoom moves a room's photos
     * to the gallery): the first stays and the rest is removed. The caller keeps an original
     * that a retained or new row still uses.
     *
     * @param list<VenuePhoto> $photos
     *
     * @return array{new: array<string, PhotoContent>, removed: list<VenuePhoto>, retained: array<string, VenuePhoto>}
     */
    private static function planPhotos(VenueContent $content, array $photos): array
    {
        $current = [];
        $surplus = [];
        foreach ($photos as $photo) {
            $room = $photo->room();
            $identity = self::photoIdentity(null === $room ? null : ($room->contentKey() ?? '#'.$room->id()), $photo->originalPath());
            if (isset($current[$identity])) {
                $surplus[] = $photo;
            } else {
                $current[$identity] = $photo;
            }
        }
        $wanted = [];
        foreach ($content->photos as $photo) {
            $wanted[self::photoIdentity(null, ContentFormat::originalPath($content->key, $photo->sha256, $photo->extension))] = $photo;
        }
        foreach ($content->rooms as $room) {
            foreach ($room->photos as $photo) {
                $wanted[self::photoIdentity($room->key, ContentFormat::originalPath($content->key, $photo->sha256, $photo->extension))] = $photo;
            }
        }

        return [
            'new' => array_diff_key($wanted, $current),
            'removed' => [...array_values(array_diff_key($current, $wanted)), ...$surplus],
            'retained' => array_intersect_key($current, $wanted),
        ];
    }

    /**
     * Matches documents by original path.
     *
     * @param list<VenueDocument> $documents
     *
     * @return array{new: array<string, string>, removed: list<VenueDocument>, retained: array<string, VenueDocument>}
     */
    private static function planDocuments(VenueContent $content, array $documents): array
    {
        $current = [];
        foreach ($documents as $document) {
            $current[$document->path()] = $document;
        }
        $wanted = [];
        foreach ($content->documents as $document) {
            $wanted[ContentFormat::originalPath($content->key, $document->sha256, 'pdf')] = $document->absolutePath;
        }

        return [
            'new' => array_diff_key($wanted, $current),
            'removed' => array_values(array_diff_key($current, $wanted)),
            'retained' => array_intersect_key($current, $wanted),
        ];
    }

    private static function photoIdentity(?string $roomKey, string $originalPath): string
    {
        return ($roomKey ?? '').'|'.$originalPath;
    }
}
