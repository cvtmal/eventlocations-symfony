<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\Form\ChangedFields;
use App\Cms\Form\CreateVenueData;
use App\Cms\Form\CreateVenueType;
use App\Cms\Form\VenueAddressData;
use App\Cms\Form\VenueEventData;
use App\Cms\Form\VenueFilterData;
use App\Cms\Form\VenueFilterType;
use App\Cms\Form\VenueTermsData;
use App\Cms\ReadModel\VenueEdit;
use App\Cms\ReadModel\VenueEditQuery;
use App\Cms\ReadModel\VenueHeaderQuery;
use App\Cms\ReadModel\VenueListFilter;
use App\Cms\ValueObject\VenueCard;
use App\Tests\Integration\Geo\ImportsTownDataset;
use App\Tests\Integration\Search\ListingQueryTest;
use App\Tests\Support\Venues\DetailVenues;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueEventFacts;
use App\Venues\Command\ChangeVenueGooglePlace;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\CreateVenue;
use App\Venues\Command\RelocateVenue;
use App\Venues\CommandHandler\ChangeVenueEventFactsHandler;
use App\Venues\CommandHandler\ChangeVenueTermsHandler;
use App\Venues\Entity\Venue;
use App\Venues\ValueObject\KnownTiers;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Zenstruck\Foundry\Test\Factories;

/**
 * The editor's card forms through the real form factory, under an `/admin` request so the back office's
 * text rule applies: every card round-trips what it loaded unchanged (D11, P5), and each card's own rules.
 * The screens (Phase 8) test the same forms over HTTP.
 */
final class VenueCardFormsTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private EntityManagerInterface $em;
    private Connection $connection;
    private VenueEditQuery $query;
    private FormFactoryInterface $forms;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $this->query = new VenueEditQuery($this->connection, new VenueHeaderQuery($this->connection, ListingQueryTest::enabledLocale()));
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        $this->forms = $forms;

        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/admin/locations/1/stammdaten', 'POST'));
    }

    protected function tearDown(): void
    {
        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        while (null !== $requests->pop()) {
            // Leave no request behind for the next test.
        }
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function venues(): iterable
    {
        yield 'full' => ['full'];
        yield 'minimal' => ['minimal'];
        yield 'canton only' => ['canton'];
        yield 'region only' => ['region'];
    }

    #[DataProvider('venues')]
    public function test_every_card_saves_what_it_loaded_as_a_no_op(string $shape): void
    {
        $edit = $this->edit($this->venue($shape));

        foreach (VenueCard::cases() as $card) {
            $form = $this->card($card, $edit);
            $form->submit(self::values($form->createView()));

            self::assertTrue($form->isSynchronized(), $card->value);
            self::assertTrue($form->isValid(), $card->value.': '.$form->getErrors(true));
            self::assertSame([], ChangedFields::between($card->dataClass()::from($edit), $form->getData()), $card->value);
        }
    }

    public function test_every_card_builds_its_command(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));

        foreach (VenueCard::cases() as $card) {
            $data = $card->dataClass()::from($edit);
            $command = get_object_vars($data->toCommand($edit->number(), $edit, ['x']));

            self::assertSame([$edit->number(), $edit->loadedAt, ['x']], [$command['venueNumber'], $command['loadedAt'], $command['changedFields']], $card->value);
        }
    }

    public function test_coordinates_round_trip_exactly(): void
    {
        $venue = DetailVenues::full($this->em);
        $this->connection->executeStatement('UPDATE venues SET lat = 47.269412, lng = 8.548023 WHERE id = ?', [$venue->id()]);
        $edit = $this->edit($venue);

        $form = $this->card(VenueCard::Address, $edit);
        $view = $form->createView();
        self::assertSame('47.269412', $view['lat']->vars['value']);
        $form->submit(self::values($view));

        $command = $form->getData()->toCommand($edit->number(), $edit, []);
        self::assertInstanceOf(RelocateVenue::class, $command);
        self::assertSame([47.269412, 8.548023], [$command->lat, $command->lng]);
    }

    public function test_a_change_names_the_field_and_reaches_the_command(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Basics, $edit);
        $values = self::values($form->createView());
        $values['name'] = 'Schloss Seeblick Neu';
        $values['tier'] = KnownTiers::FREE;
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $changed = ChangedFields::between(VenueCard::Basics->dataClass()::from($edit), $form->getData());
        self::assertSame(['name', 'tier'], $changed);
        $command = $form->getData()->toCommand($edit->number(), $edit, $changed);
        self::assertInstanceOf(ChangeVenueBasics::class, $command);
        self::assertSame(['Schloss Seeblick Neu', null, KnownTiers::FREE], [$command->name, $command->newSlug, $command->tierCode]);
    }

    public function test_a_new_slug_is_passed_only_when_it_differs(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Basics, $edit);
        $values = self::values($form->createView());
        $values['slug'] = 'schloss-am-see';
        $form->submit($values);

        $command = $form->getData()->toCommand($edit->number(), $edit, ['slug']);
        self::assertInstanceOf(ChangeVenueBasics::class, $command);
        self::assertSame('schloss-am-see', $command->newSlug);
    }

    /**
     * @return iterable<string, array{VenueCard, array<string, mixed>, string}> card, changed values, the field with the error
     */
    public static function invalid(): iterable
    {
        yield 'name without letters' => [VenueCard::Basics, ['name' => '!!!'], 'name'];
        yield 'blank name' => [VenueCard::Basics, ['name' => ''], 'name'];
        yield 'name too long' => [VenueCard::Basics, ['name' => str_repeat('a', 151)], 'name'];
        yield 'blank slug' => [VenueCard::Basics, ['slug' => ''], 'slug'];
        yield 'slug with a capital' => [VenueCard::Basics, ['slug' => 'Schloss'], 'slug'];
        yield 'slug with a hyphen at the end' => [VenueCard::Basics, ['slug' => 'schloss-'], 'slug'];
        yield 'slug of invalid UTF-8' => [VenueCard::Basics, ['slug' => "schl\xffoss"], 'slug'];
        yield 'max below min' => [VenueCard::Basics, ['capacityMin' => '200', 'capacityMax' => '100'], 'capacityMax'];
        yield 'max 0' => [VenueCard::Basics, ['capacityMax' => '0'], 'capacityMax'];
        yield 'max 50001' => [VenueCard::Basics, ['capacityMax' => '50001'], 'capacityMax'];
        yield 'unknown tier' => [VenueCard::Basics, ['tier' => 'gold'], 'tier'];
        yield 'blank summary in the default locale' => [VenueCard::Texts, ['texts' => ['de' => ['summary' => ' ']]], 'texts'];
        yield 'summary too long' => [VenueCard::Texts, ['texts' => ['de' => ['summary' => str_repeat('a', 201)]]], 'texts'];
        yield 'description with a NUL' => [VenueCard::Texts, ['texts' => ['de' => ['description' => "a\0b"]]], 'texts'];
        yield 'town without a ZIP' => [VenueCard::Address, ['town' => 'Meilen'], 'town'];
        yield 'town of invalid UTF-8' => [VenueCard::Address, ['town' => "8706 Mei\xfflen"], 'town'];
        yield 'street without a town' => [VenueCard::Address, ['town' => '', 'street' => 'Seestrasse 1'], 'street'];
        yield 'lat alone' => [VenueCard::Address, ['lng' => ''], 'lng'];
        yield 'lat out of Switzerland' => [VenueCard::Address, ['lat' => '48.1'], 'lat'];
        yield 'lng out of Switzerland' => [VenueCard::Address, ['lng' => '11'], 'lng'];
        yield 'lat not a number' => [VenueCard::Address, ['lat' => '47.3a'], 'lat'];
        yield 'lat of invalid UTF-8' => [VenueCard::Address, ['lat' => "47.\xff"], 'lat'];
        yield 'distance over 500' => [VenueCard::Address, ['stationKm' => '500.1'], 'stationKm'];
        yield 'distance with two decimals' => [VenueCard::Address, ['stationKm' => '1.25'], 'stationKm'];
        yield 'distance name without km' => [VenueCard::Address, ['highwayKm' => '', 'highwayName' => 'A3'], 'highwayKm'];
        yield 'unknown term' => [VenueCard::Terms, ['building' => ['palace-on-the-moon']], 'building'];
        yield 'main building not selected' => [VenueCard::Terms, ['building' => [], 'mainBuilding' => 'castle'], 'mainBuilding'];
        yield 'seated 0' => [VenueCard::Event, ['capacitySeated' => '0'], 'capacitySeated'];
        yield 'standing 50001' => [VenueCard::Event, ['capacityStanding' => '50001'], 'capacityStanding'];
        yield 'unknown price level' => [VenueCard::Event, ['priceLevel' => 'cheap'], 'priceLevel'];
        yield 'ambience too long' => [VenueCard::Event, ['texts' => ['de' => ['ambience' => str_repeat('a', 61)]]], 'texts'];
        yield 'capacity note with a NUL' => [VenueCard::Event, ['texts' => ['de' => ['capacityNote' => "a\0b"]]], 'texts'];
        yield 'unknown catering term' => [VenueCard::Event, ['catering' => ['palace-on-the-moon']], 'catering'];
        yield 'a code of another group' => [VenueCard::Event, ['amenity' => ['castle']], 'amenity'];
        yield 'phone of letters' => [VenueCard::Contact, ['phone' => 'Telefon'], 'phone'];
        yield 'phone of invalid UTF-8' => [VenueCard::Contact, ['phone' => "044 123 45 6\xff"], 'phone'];
        yield 'website of invalid UTF-8' => [VenueCard::Contact, ['website' => "https://exa\xffmple.ch"], 'website'];
        yield 'e-mail without a dot in the domain' => [VenueCard::Contact, ['inquiryEmail' => 'info@localhost'], 'inquiryEmail'];
        yield 'e-mail with an umlaut in the local part' => [VenueCard::Contact, ['inquiryEmail' => 'müller@example.ch'], 'inquiryEmail'];
        yield 'e-mail of invalid UTF-8' => [VenueCard::Contact, ['inquiryEmail' => "a\xff@example.ch"], 'inquiryEmail'];
        yield 'website with another scheme' => [VenueCard::Contact, ['website' => 'ftp://example.ch'], 'website'];
        yield 'website too long' => [VenueCard::Contact, ['website' => 'https://example.ch/'.str_repeat('a', 482)], 'website'];
        yield 'Instagram naming no profile' => [VenueCard::Contact, ['instagram' => 'https://example.ch'], 'instagram'];
        yield 'Instagram with a NUL' => [VenueCard::Contact, ['instagram' => "re\0mise"], 'instagram'];
        yield 'contact person with a NUL' => [VenueCard::Contact, ['contactPerson' => "An\0na"], 'contactPerson'];
        yield 'unknown catering' => [VenueCard::Faq, ['ownCatering' => 'sometimes'], 'ownCatering'];
        yield 'a token with letters' => [VenueCard::Faq, ['loadedAt' => '17000x'], ''];
        yield 'a token of invalid UTF-8' => [VenueCard::Faq, ['loadedAt' => "17000\xff"], ''];
        yield 'an unknown tier of invalid UTF-8' => [VenueCard::Basics, ['tier' => "\xff"], 'tier'];
        yield 'place ID with a space' => [VenueCard::Google, ['placeId' => 'ChIJN1t tDeuEmsRUsoyG83frY4'], 'placeId'];
        yield 'place ID of 9 characters' => [VenueCard::Google, ['placeId' => 'ChIJN1t_t'], 'placeId'];
        yield 'place ID of invalid UTF-8' => [VenueCard::Google, ['placeId' => "ChIJN1t_tDeu\xffEmsRUso"], 'placeId'];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalid')]
    public function test_a_rule_is_a_field_error(VenueCard $card, array $changes, string $field): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card($card, $edit);
        $form->submit(self::merge(self::values($form->createView()), $changes));

        self::assertFalse($form->isValid());
        $target = '' === $field ? $form : $form->get($field);
        self::assertGreaterThan(0, \count($target->getErrors(true)), (string) $form->getErrors(true));
    }

    public function test_comma_decimals_are_read_as_points(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Address, $edit);
        $values = self::values($form->createView());
        $values['stationKm'] = '0,7';
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $command = $form->getData()->toCommand($edit->number(), $edit, ['stationKm']);
        self::assertInstanceOf(RelocateVenue::class, $command);
        self::assertSame('0.7', $command->stationKm);
    }

    public function test_a_google_place_id_is_trimmed_and_reaches_the_command(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Google, $edit);
        $values = self::values($form->createView());
        $values['placeId'] = '  ChIJN1t_tDeuEmsRUsoyG83frY4 ';
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $changed = ChangedFields::between(VenueCard::Google->dataClass()::from($edit), $form->getData());
        self::assertSame(['placeId'], $changed);
        $command = $form->getData()->toCommand($edit->number(), $edit, $changed);
        self::assertInstanceOf(ChangeVenueGooglePlace::class, $command);
        self::assertSame(['ChIJN1t_tDeuEmsRUsoyG83frY4', false], [$command->placeId, $command->ratingHidden]);
    }

    public function test_a_wrong_google_place_id_names_its_rule(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Google, $edit);
        $values = self::values($form->createView());
        $values['placeId'] = 'ChIJ <script>';
        $form->submit($values);

        $errors = $form->get('placeId')->getErrors();
        self::assertCount(1, $errors);
        $error = $errors[0];
        self::assertInstanceOf(FormError::class, $error);
        self::assertSame('cms.venue.google.place_id_format', $error->getMessageTemplate());
    }

    public function test_an_emptied_google_place_id_is_null_and_takes_the_switch_along(): void
    {
        $venue = DetailVenues::full($this->em);
        $this->connection->executeStatement("UPDATE venues SET google_place_id = 'ChIJN1t_tDeuEmsRUsoyG83frY4', google_rating_hidden = true WHERE id = ?", [$venue->id()]);
        $edit = $this->edit($venue);
        $form = $this->card(VenueCard::Google, $edit);
        $view = $form->createView();
        self::assertTrue($view['ratingHidden']->vars['checked']);
        $values = self::values($view);
        $values['placeId'] = '';
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $command = $form->getData()->toCommand($edit->number(), $edit, ['placeId']);
        self::assertInstanceOf(ChangeVenueGooglePlace::class, $command);
        self::assertNull($command->placeId);
    }

    public function test_the_town_centre_press_reaches_the_command(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Address, $edit);
        $values = self::values($form->createView());
        $values['useTownCentre'] = '';
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(VenueAddressData::class, $data);
        self::assertTrue($data->useTownCentre);
        self::assertSame([], ChangedFields::between(VenueAddressData::from($edit), $data));
        $command = $data->toCommand($edit->number(), $edit, []);
        self::assertInstanceOf(RelocateVenue::class, $command);
        self::assertTrue($command->useTownCentre);
    }

    public function test_saving_without_the_button_does_not_press_it(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Address, $edit);
        $form->submit(self::values($form->createView()));

        self::assertFalse($form->getData()->useTownCentre);
    }

    public function test_regions_are_offered_only_without_town_or_canton_and_cleared_by_one(): void
    {
        self::assertFalse($this->card(VenueCard::Address, $this->edit(DetailVenues::full($this->em)))->has('regions'));
        self::assertFalse($this->card(VenueCard::Address, $this->edit(DetailVenues::inCantonOnly()))->has('regions'));

        $edit = $this->edit(DetailVenues::inRegionOnly($this->em, 'Basel'));
        $form = $this->card(VenueCard::Address, $edit);
        self::assertTrue($form->has('regions'));
        $values = self::values($form->createView());
        self::assertSame('Basel', $values['regions']['de']);

        $values['canton'] = 'bs';
        $form->submit($values);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $changed = ChangedFields::between(VenueAddressData::from($edit), $form->getData());
        self::assertSame(['canton'], $changed);
        $command = $form->getData()->toCommand($edit->number(), $edit, $changed);
        self::assertInstanceOf(RelocateVenue::class, $command);
        self::assertSame(['bs', true, ['de' => 'Basel']], [$command->cantonCode, $command->regionCleared, $command->regions]);
    }

    public function test_a_crlf_description_is_stored_with_lf(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Texts, $edit);
        $values = self::values($form->createView());
        $values['texts']['de']['description'] = "Erster Absatz.\r\n\r\nZweiter Absatz.  ";
        $form->submit($values);

        $command = $form->getData()->toCommand($edit->number(), $edit, []);
        self::assertInstanceOf(ChangeVenueTexts::class, $command);
        self::assertSame("Erster Absatz.\n\nZweiter Absatz.", $command->texts['de']['description']);
    }

    public function test_the_event_card_has_the_event_fields_and_no_party_or_curfew(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $view = $this->card(VenueCard::Event, $edit)->createView();

        self::assertSame(
            ['loadedAt', 'priceLevel', 'capacitySeated', 'capacityStanding', 'texts', 'suitable_for', 'catering', 'technology', 'amenity', 'language'],
            array_keys($view->children),
        );
        self::assertSame(['de'], array_keys($view['texts']->children));
        self::assertSame(['ambience', 'capacityNote', 'openingHours'], array_keys($view['texts']['de']->children));
    }

    public function test_the_event_card_builds_its_command(): void
    {
        $edit = $this->edit(DetailVenues::full($this->em));
        $form = $this->card(VenueCard::Event, $edit);
        $values = self::values($form->createView());
        $values['priceLevel'] = PriceLevel::Exclusive->value;
        $values['capacitySeated'] = '640';
        $values['texts']['de']['openingHours'] = 'Täglich ab 9 Uhr';
        $values['catering'] = ['vegan', 'buffet'];
        $form->submit($values);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $changed = ChangedFields::between(VenueEventData::from($edit), $form->getData());
        self::assertSame(['priceLevel', 'capacitySeated', 'texts.de.openingHours', 'terms.catering'], $changed);
        $command = $form->getData()->toCommand($edit->number(), $edit, $changed);
        self::assertInstanceOf(ChangeVenueEventFacts::class, $command);
        self::assertSame([PriceLevel::Exclusive, 640, 1500], [$command->priceLevel, $command->capacitySeated, $command->capacityStanding]);
        self::assertSame('Täglich ab 9 Uhr', $command->texts['de']['openingHours']);
        self::assertSame('Romantisch am Wasser', $command->texts['de']['ambience']);
        self::assertEqualsCanonicalizing(['vegan', 'buffet'], $command->terms['catering']);
        self::assertSame(array_map(static fn (TermGroup $group): string => $group->value, VenueEventData::GROUPS), array_keys($command->terms));
    }

    public function test_the_cards_own_the_groups_their_handlers_replace(): void
    {
        self::assertSame(ChangeVenueTermsHandler::GROUPS, VenueTermsData::GROUPS);
        self::assertSame(ChangeVenueEventFactsHandler::GROUPS, VenueEventData::GROUPS);
    }

    public function test_every_term_group_is_edited_by_exactly_one_card(): void
    {
        $value = static fn (TermGroup $group): string => $group->value;
        $owned = array_map($value, [...VenueTermsData::GROUPS, ...VenueEventData::GROUPS]);
        $all = array_map($value, TermGroup::cases());
        sort($owned);
        sort($all);

        self::assertSame($all, $owned);
    }

    public function test_the_create_form_builds_its_command(): void
    {
        $form = $this->forms->create(CreateVenueType::class, new CreateVenueData(), ['csrf_protection' => false]);
        $form->submit(['name' => 'Neue Scheune', 'tier' => 'free', 'capacityMax' => '120']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $command = $form->getData()->toCommand();
        self::assertInstanceOf(CreateVenue::class, $command);
        self::assertSame(['Neue Scheune', KnownTiers::FREE, 120], [$command->name, $command->tierCode, $command->capacityMax]);

        $refused = $this->forms->create(CreateVenueType::class, new CreateVenueData(), ['csrf_protection' => false]);
        $refused->submit(['name' => '!!!', 'tier' => 'free', 'capacityMax' => '120']);
        self::assertFalse($refused->isValid());
    }

    public function test_the_filter_form_builds_its_filter(): void
    {
        $form = $this->forms->createNamed('', VenueFilterType::class, new VenueFilterData());
        $form->submit(['q' => 'zuerich', 'tier' => 'pro', 'canton' => 'none', 'address' => 'missing', 'status' => 'incomplete', 'sort' => 'name'], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertEquals(new VenueListFilter('zuerich', 'incomplete', KnownTiers::PRO, 'none', true), $form->getData()->toFilter());

        $refused = $this->forms->createNamed('', VenueFilterType::class, new VenueFilterData());
        $refused->submit(['status' => 'deleted', 'canton' => 'xx'], false);
        self::assertFalse($refused->isValid());
        self::assertEquals(new VenueListFilter(), new VenueFilterData()->toFilter());
    }

    /**
     * Replaces values; a map (a locale's texts) is merged key by key, a list (chips) is replaced whole.
     *
     * @param array<string, mixed> $changes
     */
    private static function merge(mixed $values, array $changes): mixed
    {
        \assert(\is_array($values));
        foreach ($changes as $key => $change) {
            $values[$key] = \is_array($change) && !array_is_list($change) ? self::merge($values[$key] ?? [], $change) : $change;
        }

        return $values;
    }

    private function venue(string $shape): Venue
    {
        return match ($shape) {
            'full' => DetailVenues::full($this->em),
            'minimal' => DetailVenues::minimal(),
            'canton' => DetailVenues::inCantonOnly(),
            'region' => DetailVenues::inRegionOnly($this->em, 'Basel'),
            default => throw new \LogicException($shape),
        };
    }

    private function edit(Venue $venue): VenueEdit
    {
        $edit = $this->query->find($venue->number());
        self::assertNotNull($edit);

        return $edit;
    }

    private function card(VenueCard $card, VenueEdit $edit): FormInterface
    {
        return $this->forms->createNamed($card->formName(), $card->formType(), $card->dataClass()::from($edit), [
            'venue' => $edit,
            'csrf_protection' => false,
        ]);
    }

    /**
     * What a browser would send for the rendered form: each field's value, a single checkbox only when checked,
     * the checked boxes and radios of an expanded choice, no button.
     */
    private static function values(FormView $view): mixed
    {
        if (\in_array('checkbox', $view->vars['block_prefixes'], true)) {
            return true === $view->vars['checked'] ? $view->vars['value'] : null;
        }
        if (!($view->vars['compound'] ?? false)) {
            return $view->vars['value'];
        }
        if (($view->vars['expanded'] ?? false) === true) {
            $checked = [];
            foreach ($view->children as $child) {
                if (true === $child->vars['checked']) {
                    $checked[] = $child->vars['value'];
                }
            }

            return true === $view->vars['multiple'] ? $checked : ($checked[0] ?? null);
        }

        $values = [];
        foreach ($view->children as $name => $child) {
            if (\in_array('button', $child->vars['block_prefixes'], true)) {
                continue;
            }
            $values[$name] = self::values($child);
        }

        return $values;
    }
}
