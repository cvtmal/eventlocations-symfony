<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Tests\Support\Content\WixCsv;
use App\Venues\OwnerPhotoList;
use App\Venues\ValueObject\Content\OwnerPhoto;
use PHPUnit\Framework\TestCase;

final class OwnerPhotoListTest extends TestCase
{
    public function test_the_cover_comes_first_then_the_nodes_in_text_order(): void
    {
        $row = WixCsv::row([
            'Anzeigebild' => WixCsv::cover('aa11_cover~mv2.jpg'),
            'Vorstellungstext' => WixCsv::richText(
                WixCsv::paragraph('Beschrieb:'),
                WixCsv::photo('bb22_eins~mv2.jpg', 'Erfundener Saal'),
                WixCsv::list('Ein Punkt', WixCsv::list('Unterpunkt')),
                ['type' => 'BULLETED_LIST', 'nodes' => [['type' => 'LIST_ITEM', 'nodes' => [WixCsv::photo('cc33_zwei~mv2.png')]]]],
                WixCsv::photo('dd44_drei~mv2.webp', 'Fiktiver Garten'),
            ),
        ]);

        $notes = [];
        self::assertEquals([
            new OwnerPhoto('aa11_cover~mv2.jpg', null),
            new OwnerPhoto('bb22_eins~mv2.jpg', 'Erfundener Saal'),
            new OwnerPhoto('cc33_zwei~mv2.png', null),
            new OwnerPhoto('dd44_drei~mv2.webp', 'Fiktiver Garten'),
        ], OwnerPhotoList::fromRow($row, $notes));
        self::assertSame([], $notes);
    }

    public function test_a_repeated_cover_keeps_position_one_and_gains_the_alt_of_its_node(): void
    {
        $row = WixCsv::row([
            'Anzeigebild' => WixCsv::cover('aa11_cover~mv2.jpg'),
            'Vorstellungstext' => WixCsv::richText(
                WixCsv::photo('bb22_eins~mv2.jpg', 'Erster'),
                WixCsv::photo('aa11_cover~mv2.jpg', 'Die erfundene Fassade'),
                WixCsv::photo('bb22_eins~mv2.jpg', 'Zweimal'),
            ),
        ]);

        self::assertEquals([
            new OwnerPhoto('aa11_cover~mv2.jpg', 'Die erfundene Fassade'),
            new OwnerPhoto('bb22_eins~mv2.jpg', 'Erster'),
        ], OwnerPhotoList::fromRow($row));
    }

    public function test_a_malformed_cover_is_skipped_and_noted(): void
    {
        $row = WixCsv::row([
            'Anzeigebild' => 'wix:image://v1/fiktiv.jpg',
            'Vorstellungstext' => WixCsv::richText(WixCsv::photo('bb22_eins~mv2.jpg')),
        ]);

        $notes = [];
        self::assertEquals([new OwnerPhoto('bb22_eins~mv2.jpg', null)], OwnerPhotoList::fromRow($row, $notes));
        self::assertSame([OwnerPhotoList::NOTE_COVER_MALFORMED], $notes);
    }

    public function test_an_empty_cover_and_text_give_no_photo(): void
    {
        $notes = [];
        self::assertSame([], OwnerPhotoList::fromRow(WixCsv::row(['Anzeigebild' => '', 'Vorstellungstext' => '']), $notes));
        self::assertSame([], $notes);
    }

    public function test_an_alt_above_700_characters_is_dropped_and_noted(): void
    {
        $row = WixCsv::row([
            'Anzeigebild' => '',
            'Vorstellungstext' => WixCsv::richText(WixCsv::photo('bb22_eins~mv2.jpg', str_repeat('a', 701)), WixCsv::photo('cc33_zwei~mv2.jpg', str_repeat('b', 700))),
        ]);

        $notes = [];
        self::assertEquals([new OwnerPhoto('bb22_eins~mv2.jpg', null), new OwnerPhoto('cc33_zwei~mv2.jpg', str_repeat('b', 700))], OwnerPhotoList::fromRow($row, $notes));
        self::assertSame([OwnerPhotoList::NOTE_ALT_TOO_LONG], $notes);
    }
}
