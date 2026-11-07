<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\Form\VenueEventData;
use App\Cms\Form\VenueEventType;
use App\Cms\ValueObject\VenueCard;
use PHPUnit\Framework\TestCase;

final class VenueCardTest extends TestCase
{
    public function test_the_cards_are_in_page_order_with_the_event_card_after_the_terms(): void
    {
        self::assertSame(
            ['basics', 'texts', 'address', 'terms', 'event', 'contact', 'faq', 'google'],
            array_map(static fn (VenueCard $card): string => $card->value, VenueCard::cases()),
        );
    }

    public function test_the_event_card_is_the_veranstaltung_card(): void
    {
        $card = VenueCard::Event;

        self::assertSame('veranstaltung', $card->anchor());
        self::assertSame('admin_locations_event', $card->route());
        self::assertSame('venue_event', $card->formName());
        self::assertSame(VenueEventType::class, $card->formType());
        self::assertSame(VenueEventData::class, $card->dataClass());
    }

    public function test_no_card_keeps_the_wedding_value(): void
    {
        self::assertNotContains('wedding', array_map(static fn (VenueCard $card): string => $card->value, VenueCard::cases()));
        self::assertNotContains('hochzeit', array_map(static fn (VenueCard $card): string => $card->anchor(), VenueCard::cases()));
    }
}
