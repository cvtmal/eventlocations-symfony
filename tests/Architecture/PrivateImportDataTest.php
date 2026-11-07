<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Venues\Entity\PhotoCandidate;
use App\Venues\Entity\SourceRecord;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * G3, D16: the facts and photo references the website importer finds are working data for staff and for the claim
 * flow, never page content. Whatever serves a page, a form, a mail or a back-office screen may not reach the two
 * entities or the importer; the staff screen G3 may add later reads through a ReadModel in SQL.
 */
final class PrivateImportDataTest extends ArchitectureRules
{
    public function test_nothing_that_serves_a_page_reads_the_import_data(): Rule
    {
        return PHPat::rule()
            ->classes(
                self::layer('Controller'),
                self::layer('ReadModel'),
                self::layer('Twig'),
                self::layer('Form'),
                self::layer('Mailer'),
                self::layer('EventListener'),
                self::layer('Extension'),
            )
            ->shouldNot()->dependOn()
            ->classes(
                Selector::classname(SourceRecord::class),
                Selector::classname(PhotoCandidate::class),
                Selector::inNamespace('App\Venues\SiteImport'),
            )
            ->because('Imported facts and photo candidates are private working data (D16); a page that read them would publish them.');
    }
}
