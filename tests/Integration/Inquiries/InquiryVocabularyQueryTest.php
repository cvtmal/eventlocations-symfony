<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Inquiries\ReadModel\InquiryVocabularyQuery;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inquiry's event types and catering wishes come from the venue vocabulary of the migrated `app_test` (F3), and
 * every label is a German term label (keys built at runtime are invisible to debug:translation).
 */
final class InquiryVocabularyQueryTest extends KernelTestCase
{
    public function test_the_event_types_are_the_suitable_for_terms_in_order(): void
    {
        $codes = array_map(static fn (Term $term): string => $term->code, $this->query()->eventTypes());

        self::assertCount(17, $codes);
        self::assertSame(['corporate_event', 'seminar', 'conference'], \array_slice($codes, 0, 3));
        self::assertContains('wedding', $codes);
        foreach ($this->query()->eventTypes() as $term) {
            self::assertSame(TermGroup::SuitableFor, $term->group);
        }
    }

    public function test_the_catering_wishes_are_the_two_who_caters_terms(): void
    {
        $wishes = $this->query()->cateringWishes();

        self::assertSame(InquiryVocabularyQuery::CATERING_WISHES, array_map(static fn (Term $term): string => $term->code, $wishes));
        foreach ($wishes as $term) {
            self::assertSame(TermGroup::Catering, $term->group);
        }
    }

    public function test_it_knows_its_codes_and_nothing_else(): void
    {
        $query = $this->query();

        self::assertTrue($query->isEventType('general_assembly'));
        self::assertFalse($query->isEventType('in_house'));
        self::assertFalse($query->isEventType('civil_wedding'));
        self::assertTrue($query->isCateringWish('external_allowed'));
        self::assertFalse($query->isCateringWish('buffet'));
        self::assertFalse($query->isCateringWish('corporate_event'));
    }

    public function test_every_choice_has_a_german_label(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        foreach ([...$this->query()->eventTypes(), ...$this->query()->cateringWishes()] as $term) {
            self::assertStringNotContainsString('term.', $term->trans($translator, 'de'));
        }
        self::assertSame('Firmenanlass', (new Term(TermGroup::SuitableFor, 'corporate_event'))->trans($translator, 'de'));
        self::assertSame('Hauseigenes Catering', (new Term(TermGroup::Catering, 'in_house'))->trans($translator, 'de'));
    }

    private function query(): InquiryVocabularyQuery
    {
        $query = self::getContainer()->get(InquiryVocabularyQuery::class);
        self::assertInstanceOf(InquiryVocabularyQuery::class, $query);

        return $query;
    }
}
