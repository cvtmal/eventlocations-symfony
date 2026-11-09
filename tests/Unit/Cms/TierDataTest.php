<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cms;

use App\Cms\Form\ChangedFields;
use App\Cms\Form\TierData;
use App\Venues\Command\ReviseTier;
use App\Venues\ReadModel\TierDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The rules of the tier form (F12): what the data class refuses, and what it hands the command.
 */
final class TierDataTest extends TestCase
{
    public function test_the_values_of_a_stored_tier_are_valid(): void
    {
        self::assertCount(0, self::validator()->validate(TierData::from(self::pro())));
        self::assertCount(0, self::validator()->validate(TierData::from(self::free())));
    }

    /**
     * @return iterable<string, array{string, \Closure(TierData): mixed, string}> property, change, message key
     */
    public static function refused(): iterable
    {
        yield 'no name' => ['name', static fn (TierData $data) => $data->name = null, 'cms.tier.name.required'];
        yield 'a blank name' => ['name', static fn (TierData $data) => $data->name = '', 'cms.tier.name.required'];
        yield 'a name of 61 characters' => ['name', static fn (TierData $data) => $data->name = str_repeat('a', 61), 'cms.tier.too_long'];
        yield 'no price' => ['priceRappen', static fn (TierData $data) => $data->priceRappen = null, 'cms.tier.price.required'];
        yield 'a negative price' => ['priceRappen', static fn (TierData $data) => $data->priceRappen = -1, 'cms.tier.price.range'];
        yield 'a price above CHF 100000' => ['priceRappen', static fn (TierData $data) => $data->priceRappen = 10_000_001, 'cms.tier.price.range'];
        yield 'no rank weight' => ['rankWeight', static fn (TierData $data) => $data->rankWeight = null, 'cms.tier.number.required'];
        yield 'a negative rank weight' => ['rankWeight', static fn (TierData $data) => $data->rankWeight = -1, 'cms.tier.range'];
        yield 'a rank weight above 100' => ['rankWeight', static fn (TierData $data) => $data->rankWeight = 101, 'cms.tier.range'];
        yield 'no photo limit' => ['photosMax', static fn (TierData $data) => $data->photosMax = null, 'cms.tier.number.required'];
        yield 'a negative photo limit' => ['photosMax', static fn (TierData $data) => $data->photosMax = -1, 'cms.tier.range'];
        yield 'a photo limit above 1000' => ['photosMax', static fn (TierData $data) => $data->photosMax = 1001, 'cms.tier.range'];
        yield 'a room limit above 1000' => ['roomsMax', static fn (TierData $data) => $data->roomsMax = 1001, 'cms.tier.range'];
        yield 'a video limit above 1000' => ['videosMax', static fn (TierData $data) => $data->videosMax = 1001, 'cms.tier.range'];
        yield 'a document limit above 1000' => ['documentsMax', static fn (TierData $data) => $data->documentsMax = 1001, 'cms.tier.range'];
        yield 'zero event types' => ['eventTypesMax', static fn (TierData $data) => $data->eventTypesMax = 0, 'cms.tier.range'];
        yield 'more than 1000 event types' => ['eventTypesMax', static fn (TierData $data) => $data->eventTypesMax = 1001, 'cms.tier.range'];
    }

    /**
     * @param \Closure(TierData): mixed $change
     */
    #[DataProvider('refused')]
    public function test_a_value_out_of_range_is_refused_on_its_field(string $property, \Closure $change, string $message): void
    {
        $data = TierData::from(self::pro());
        $change($data);

        $violations = self::validator()->validate($data);

        self::assertCount(1, $violations);
        self::assertSame($property, $violations->get(0)->getPropertyPath());
        self::assertSame($message, $violations->get(0)->getMessageTemplate());
    }

    public function test_the_edges_are_valid(): void
    {
        $data = TierData::from(self::free());
        $data->name = str_repeat('ä', 60);
        $data->priceRappen = 0;
        $data->rankWeight = 100;
        $data->photosMax = 0;
        $data->roomsMax = 1000;
        $data->videosMax = 0;
        $data->documentsMax = 1000;
        $data->eventTypesMax = 1000;

        self::assertCount(0, self::validator()->validate($data));

        $data->priceRappen = TierData::MAX_PRICE_RAPPEN;
        $data->eventTypesMax = null;

        self::assertCount(0, self::validator()->validate($data));
    }

    public function test_the_command_carries_every_value_and_the_changed_fields(): void
    {
        $data = TierData::from(self::free());
        $data->name = 'Free Plus';
        $data->priceRappen = 4950;
        $data->contactVisible = true;
        $data->eventTypesMax = null;

        $changed = ChangedFields::between(TierData::from(self::free()), $data);
        $command = $data->toCommand('free', $changed);

        self::assertSame(['name', 'priceRappen', 'contactVisible', 'eventTypesMax'], $changed);
        self::assertEquals(new ReviseTier('free', 'Free Plus', 4950, false, 0, 6, 1, 1, 1, true, false, false, null, $changed), $command);
        self::assertSame(['changed' => $changed], $command->auditSummary());
        self::assertSame('tier', $command->auditSubject()->type);
        self::assertSame('free', $command->auditSubject()->id);
    }

    public function test_untouched_values_have_no_changed_field(): void
    {
        self::assertSame([], ChangedFields::between(TierData::from(self::pro()), TierData::from(self::pro())));
    }

    public function test_a_name_with_spaces_around_it_is_no_change(): void
    {
        $data = TierData::from(self::pro());
        $data->name = '  Pro ';

        self::assertSame([], ChangedFields::between(TierData::from(self::pro()), $data));
    }

    public function test_a_command_is_not_built_from_an_incomplete_form(): void
    {
        $data = TierData::from(self::pro());
        $data->priceRappen = null;

        $this->expectException(\LogicException::class);

        $data->toCommand('pro', []);
    }

    private static function free(): TierDefinition
    {
        return new TierDefinition('free', 'Gratis', 0, false, 0, 6, 1, 1, 1, false, false, false, 3, 40);
    }

    private static function pro(): TierDefinition
    {
        return new TierDefinition('pro', 'Pro', 19900, false, 10, 60, 20, 5, 5, true, true, true, null, 12);
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
