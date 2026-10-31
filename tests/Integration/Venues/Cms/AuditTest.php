<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues\Cms;

use App\Factory\VenueFactory;
use App\Venues\Command\AddRoom;
use App\Venues\Command\AddVenueDocument;
use App\Venues\Command\AddVenueReview;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #25 Step 5.6, PF6: one command of each media family (photo, room, document, review) writes an
 * admin_audit_log row through the real bus, with the venue as subject and no free text, file
 * path or name in `changes` (the append-only log is not purged).
 */
final class AuditTest extends KernelTestCase
{
    use Factories;
    use HandlesVenueMedia;

    private const string SECRET = 'GEHEIMTEXT';

    public function test_every_media_family_is_audited_on_the_venue_without_free_text(): void
    {
        $venue = VenueFactory::createOne();
        $number = $venue->number();
        $this->em->clear();

        $this->addPhoto($number, $this->upload(name: self::SECRET.'.jpg'));
        $this->dispatch(new AddRoom($number, ['de' => self::SECRET], ['de' => self::SECRET], null, 80, 100, '3.50', null, []));
        $this->em->clear();
        $this->dispatch(new AddVenueDocument($number, $this->uploadPdf(self::SECRET), ['de' => self::SECRET]));
        $this->em->clear();
        $this->dispatch(new AddVenueReview($number, 'de', self::SECRET, 'visitor_f', '2026-09', self::SECRET));

        $rows = $this->auditRows($number);
        self::assertSame(['AddVenuePhoto', 'AddRoom', 'AddVenueDocument', 'AddVenueReview'], array_map(
            static fn (array $row): string => substr($row['command'], (int) strrpos($row['command'], '\\') + 1),
            $rows,
        ));
        foreach ($rows as $row) {
            $changes = json_encode($row['changes'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::SECRET, $changes, $row['command']);
            self::assertStringNotContainsString('/', $changes, $row['command'].': no file path');
        }
    }
}
