<?php

declare(strict_types=1);

namespace App\Tests\Integration\Portal;

use App\Factory\VenueFactory;
use App\Factory\VenueTranslationFactory;
use App\Portal\ManagerUploadGrant;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Venues\Cms\HandlesVenueMedia;
use App\Tests\Support\Cms\ResetsContentBlocks;
use App\Tests\Support\Venues\DetailVenues;
use App\Tests\Support\Venues\PublicPhotos;
use App\Venues\Command\GrantPhotoRights;
use App\Venues\Entity\Venue;
use App\Venues\RightsPolicy;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\Grantor;
use App\Venues\ValueObject\GrantorType;
use App\Venues\ValueObject\RightsPolicyText;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * What a manager's upload is granted (G1): public at once only under that manager's own declaration, and recorded
 * under the words the manager declared to, not the words staff wrote since. Staff add and swap the managers of a
 * venue, so a colleague's declaration covers nothing. Real uploads, each test in a rolled-back transaction.
 */
final class ManagerUploadGrantTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia {
        setUp as private setUpMedia;
        tearDown as private tearDownMedia;
    }
    use ImportsTownDataset;
    use ResetsContentBlocks;

    private const string MANAGER = '019a0000-0000-7000-8000-0000000000aa';
    private const string COLLEAGUE = '019a0000-0000-7000-8000-0000000000bb';

    protected function setUp(): void
    {
        $this->setUpMedia();
        $this->resetContentBlocks();
    }

    protected function tearDown(): void
    {
        $this->resetContentBlocks();
        $this->tearDownMedia();
    }

    public function test_without_a_declaration_the_upload_has_no_grant_and_waits(): void
    {
        $venue = $this->venue();

        self::assertNull($this->grants()->forUpload(self::MANAGER, $venue->number()));
        self::assertNull($this->grants()->forUpload(self::MANAGER, 999_999_999), 'an unknown venue has no declaration either');
        $this->addPhoto($venue->number(), $this->upload(), grant: $this->grants()->forUpload(self::MANAGER, $venue->number()));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM venue_photos WHERE venue_id = ?', [$venue->id()]), 'the photo is stored');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_id = ?', [$venue->id()]));
        self::assertSame([], PublicPhotos::of($this->connection, $this->translator(), $venue->number())['detail'], 'and it waits for the declaration');
    }

    public function test_a_colleagues_declaration_does_not_cover_my_upload(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $this->addPhoto($number, $this->upload());
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::COLLEAGUE), GrantEvidence::Declaration));

        $mine = $this->grants()->forUpload(self::MANAGER, $number);
        $theirs = $this->grants()->forUpload(self::COLLEAGUE, $number);

        self::assertNull($mine, 'the colleague accepted the text, I did not');
        self::assertNotNull($theirs);
        self::assertSame([GrantorType::Manager, self::COLLEAGUE, GrantEvidence::Upload], [$theirs->grantor->type, $theirs->grantor->ref, $theirs->evidence]);
        $photo = $this->addPhoto($number, $this->upload(), grant: $mine)->photoId;
        self::assertNotContains($photo, PublicPhotos::of($this->connection, $this->translator(), $number)['detail'], 'my photo waits for my declaration');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM media_rights_grants WHERE venue_photo_id = ?', [$photo]));
    }

    public function test_the_upload_records_the_version_the_manager_declared_to_and_not_the_text_in_force(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $this->addPhoto($number, $this->upload());
        $declared = $this->policy()->current();
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration));

        // Staff rewrite the declaration text after the manager accepted it.
        $edited = "**Bildrechte-Erklärung**\n\nNeuer Wortlaut.";
        $this->saveBlock($edited);
        $inForce = RightsPolicyText::of($edited);
        self::assertSame($inForce->version, $this->policy()->current()->version);
        self::assertNotSame($declared->version, $inForce->version);

        $grant = $this->grants()->forUpload(self::MANAGER, $number);
        self::assertNotNull($grant);
        self::assertSame($declared->version, $grant->policyVersion, 'the manager declared to the old words');
        $photo = $this->addPhoto($number, $this->upload(), grant: $grant)->photoId;

        $row = $this->connection->fetchAssociative('SELECT grantor_type, grantor_ref, evidence, policy_version, scope_derivatives FROM media_rights_grants WHERE venue_photo_id = ?', [$photo]);
        self::assertSame(['manager', self::MANAGER, 'upload', $declared->version, false], $row ? array_values($row) : null);
        self::assertContains($photo, PublicPhotos::of($this->connection, $this->translator(), $number)['detail'], 'the declaration makes the upload public at once');
        self::assertSame($declared->markdown, $this->connection->fetchOne('SELECT body_markdown FROM rights_policy_versions WHERE version = ?', [$declared->version]), 'the words can be read back');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM rights_policy_versions WHERE version = ?', [$inForce->version]), 'nobody agreed to the new words yet, so they are not written');
    }

    public function test_a_new_declaration_moves_the_upload_to_the_text_it_was_made_under(): void
    {
        $venue = $this->venue();
        $number = $venue->number();
        $this->addPhoto($number, $this->upload());
        $first = $this->policy()->current();
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration));
        $edited = "**Bildrechte-Erklärung**\n\nNeuer Wortlaut.";
        $this->saveBlock($edited);
        // A photo of a colleague waits, and the manager declares again, now to the new words.
        $this->addPhoto($number, $this->upload());
        $this->dispatch(new GrantPhotoRights($number, Grantor::manager(self::MANAGER), GrantEvidence::Declaration));

        $grant = $this->grants()->forUpload(self::MANAGER, $number);

        self::assertNotNull($grant);
        self::assertSame(RightsPolicyText::of($edited)->version, $grant->policyVersion, 'the latest declaration counts');
        self::assertNotSame($first->version, $grant->policyVersion);
    }

    private function venue(): Venue
    {
        $venue = VenueFactory::createOne(['name' => 'Seehaus Verwalter', 'town' => DetailVenues::town('Zürich')]);
        VenueTranslationFactory::createOne(['venue' => $venue, 'slug' => 'seehaus-verwalter']);

        return $venue;
    }

    private function grants(): ManagerUploadGrant
    {
        $grants = self::getContainer()->get(ManagerUploadGrant::class);
        self::assertInstanceOf(ManagerUploadGrant::class, $grants);

        return $grants;
    }

    private function policy(): RightsPolicy
    {
        $policy = self::getContainer()->get(RightsPolicy::class);
        self::assertInstanceOf(RightsPolicy::class, $policy);

        return $policy;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator;
    }

    /**
     * What a save in the text block screen leaves: a row, read from a fresh snapshot.
     */
    private function saveBlock(string $body): void
    {
        $this->connection->executeStatement(
            "INSERT INTO content_blocks (key, locale, body_markdown, updated_at) VALUES ('rights.declaration', 'de', ?, CURRENT_TIMESTAMP)",
            [$body."\n"],
        );
        $this->resetContentBlocks();
    }
}
