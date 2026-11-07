<?php

declare(strict_types=1);

namespace App\Tests\Unit\Portal;

use App\Portal\ValueObject\LocationCard;
use App\Portal\ValueObject\WizardStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WizardStepTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, WizardStep}>
     */
    public static function gaps(): iterable
    {
        yield 'summary' => [['summary'], WizardStep::Texts];
        yield 'main building' => [['main_building'], WizardStep::Terms];
        yield 'photo' => [['photo'], WizardStep::Photos];
        yield 'inquiry address' => [['inquiry_email'], WizardStep::Contact];
        yield 'summary and main building' => [['summary', 'main_building'], WizardStep::Texts];
        yield 'summary and photo' => [['summary', 'photo'], WizardStep::Texts];
        yield 'summary and inquiry address' => [['summary', 'inquiry_email'], WizardStep::Texts];
        yield 'main building and photo' => [['main_building', 'photo'], WizardStep::Terms];
        yield 'main building and inquiry address' => [['main_building', 'inquiry_email'], WizardStep::Terms];
        yield 'photo and inquiry address' => [['photo', 'inquiry_email'], WizardStep::Photos];
        yield 'the wizard\'s order decides, not the list\'s' => [['inquiry_email', 'summary'], WizardStep::Texts];
    }

    /**
     * @param list<string> $missing
     */
    #[DataProvider('gaps')]
    public function test_the_first_gap_decides_the_step(array $missing, WizardStep $expected): void
    {
        self::assertSame($expected, WizardStep::derive($missing));
    }

    public function test_nothing_missing_lands_on_the_check(): void
    {
        self::assertSame(WizardStep::Check, WizardStep::derive([]));
    }

    public function test_event_is_never_derived(): void
    {
        foreach (self::gaps() as [$missing]) {
            self::assertNotSame(WizardStep::Event, WizardStep::derive($missing));
        }
    }

    public function test_a_gap_maps_to_its_own_step(): void
    {
        self::assertSame(WizardStep::Texts, WizardStep::forGap('summary'));
        self::assertSame(WizardStep::Terms, WizardStep::forGap('main_building'));
        self::assertSame(WizardStep::Photos, WizardStep::forGap('photo'));
        self::assertSame(WizardStep::Contact, WizardStep::forGap('inquiry_email'));
    }

    public function test_an_unknown_gap_is_refused(): void
    {
        $this->expectException(\LogicException::class);

        WizardStep::forGap('address');
    }

    public function test_next_walks_every_step_in_order(): void
    {
        $step = WizardStep::Texts;
        $walked = [$step];
        while (WizardStep::Check !== $step) {
            $step = $step->next();
            $walked[] = $step;
        }

        self::assertSame(WizardStep::cases(), $walked);
        self::assertSame(WizardStep::Check, WizardStep::Check->next());
    }

    public function test_terms_and_event_share_a_position(): void
    {
        self::assertSame(3, WizardStep::Terms->position());
        self::assertSame(3, WizardStep::Event->position());
        self::assertSame(
            [2, 3, 3, 4, 5, 6],
            array_map(static fn (WizardStep $step): int => $step->position(), WizardStep::cases()),
        );
        self::assertSame(WizardStep::LAST_POSITION, WizardStep::Check->position());
    }

    public function test_the_requirement_lists_every_case(): void
    {
        self::assertSame(
            WizardStep::REQUIREMENT,
            implode('|', array_map(static fn (WizardStep $step): string => $step->value, WizardStep::cases())),
        );
    }

    public function test_the_event_step_is_the_veranstaltung_page_and_the_wedding_page_is_gone(): void
    {
        self::assertSame(WizardStep::Event, WizardStep::from('veranstaltung'));
        self::assertNotContains('hochzeit', array_map(static fn (WizardStep $step): string => $step->value, WizardStep::cases()));
        self::assertSame('veranstaltung', LocationCard::Event->anchor());
        self::assertSame('portal_location_event', LocationCard::Event->route());
        self::assertSame('venue_event', LocationCard::Event->formName());
    }

    public function test_only_the_card_steps_have_a_card(): void
    {
        self::assertSame(LocationCard::Texts, WizardStep::Texts->card());
        self::assertSame(LocationCard::Terms, WizardStep::Terms->card());
        self::assertSame(LocationCard::Event, WizardStep::Event->card());
        self::assertSame(LocationCard::Contact, WizardStep::Contact->card());
        self::assertNull(WizardStep::Photos->card());
        self::assertNull(WizardStep::Check->card());
    }
}
