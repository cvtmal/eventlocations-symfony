<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\Form\DocumentTitleType;
use App\Cms\Form\DocumentUploadData;
use App\Cms\Form\DocumentUploadType;
use App\Cms\Form\PhotoAltData;
use App\Cms\Form\ReviewData;
use App\Cms\Form\ReviewType;
use App\Cms\Form\RoomData;
use App\Cms\Form\RoomType;
use App\Tests\Support\Content\ContentFolder;
use App\Tests\Support\Media\MediaFiles;
use App\Tests\Support\Media\TestImages;
use App\Venues\Command\AddRoom;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * #25's forms and the alt DTO through the real form factory and validator, under an `/admin` request so the
 * back office's text rule (CleanText) applies. The endpoints and pages (Phases 7 and 8) test them over HTTP.
 */
final class VenueMediaFormsTest extends KernelTestCase
{
    private FormFactoryInterface $forms;
    private string $uploads;

    protected function setUp(): void
    {
        $forms = self::getContainer()->get(FormFactoryInterface::class);
        self::assertInstanceOf(FormFactoryInterface::class, $forms);
        $this->forms = $forms;
        $this->uploads = sys_get_temp_dir().'/cms-media-forms-'.bin2hex(random_bytes(4));

        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(Request::create('/admin/api/cms/venues/1/rooms', 'POST'));
    }

    protected function tearDown(): void
    {
        $requests = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        while (null !== $requests->pop()) {
            // Leave no request behind for the next test.
        }
        MediaFiles::removeDirectory($this->uploads);
        parent::tearDown();
    }

    public function test_a_room_builds_its_commands_with_every_seating_type(): void
    {
        $form = $this->room(self::roomPayload());

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(RoomData::class, $data);
        $command = $data->toAddCommand(7);
        self::assertInstanceOf(AddRoom::class, $command);
        self::assertSame(['de' => 'Rittersaal'], $command->names);
        self::assertSame(['de' => 'Mit Kamin.'], $command->descriptions);
        self::assertSame([20, 120, 180, '4.2', 'parquet'], [$command->capacityMin, $command->capacityMax, $command->areaM2, $command->heightM, $command->floorMaterial]);
        self::assertSame([
            'seminar' => ['min' => null, 'max' => null],
            'banquet' => ['min' => 40, 'max' => 100],
            'apero' => ['min' => null, 'max' => null],
            'concert' => ['min' => null, 'max' => null],
        ], $command->seating);
    }

    public function test_a_room_change_needs_its_token(): void
    {
        $form = $this->room(self::roomPayload(), withToken: true);
        self::assertSame(['loadedAt'], array_keys(self::errors($form)));

        foreach (["\xff", "1\x002"] as $tampered) {
            $form = $this->room(['loadedAt' => $tampered] + self::roomPayload(), withToken: true);
            self::assertSame(['loadedAt'], array_keys(self::errors($form)));
        }

        $form = $this->room(['loadedAt' => '1730000000'] + self::roomPayload(), withToken: true);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(RoomData::class, $data);
        self::assertSame('1730000000', $data->toChangeCommand(7, 'room-id')->loadedAt);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRooms(): iterable
    {
        yield 'no name' => [['names' => ['de' => '']], 'names.de'];
        yield 'name with a NUL' => [['names' => ['de' => "Ritter\0saal"]], 'names.de'];
        yield 'name too long' => [['names' => ['de' => str_repeat('a', 101)]], 'names.de'];
        yield 'description with invalid UTF-8' => [['descriptions' => ['de' => "Kamin\xff"]], 'descriptions.de'];
        yield 'min above max' => [['capacityMin' => 200], 'capacityMax'];
        yield 'capacity out of range' => [['capacityMax' => 50001], 'capacityMax'];
        yield 'capacity not a number' => [['capacityMax' => 'viele'], 'capacityMax'];
        yield 'area zero' => [['areaM2' => 0], 'areaM2'];
        yield 'height not a decimal' => [['heightM' => 'hoch'], 'heightM'];
        yield 'height above 30' => [['heightM' => '31'], 'heightM'];
        yield 'height zero' => [['heightM' => '0'], 'heightM'];
        yield 'unknown floor' => [['floorMaterial' => 'marble'], 'floorMaterial'];
        yield 'seating min above max' => [['seating' => ['banquet' => ['min' => 120, 'max' => 100]]], 'seating.banquet.max'];
        yield 'seating out of range' => [['seating' => ['apero' => ['min' => 0]]], 'seating.apero.min'];
        yield 'another locale' => [['names' => ['de' => 'Saal', 'xx' => 'Hall']], 'names'];
        yield 'another seating type' => [['seating' => ['disco' => ['min' => 1]]], 'seating'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidRooms')]
    public function test_a_room_refuses(array $override, string $field): void
    {
        $form = $this->room(array_replace(self::roomPayload(), $override));

        self::assertFalse($form->isValid());
        self::assertArrayHasKey($field, self::errors($form), (string) $form->getErrors(true));
    }

    public function test_photo_alts_build_the_command(): void
    {
        $data = PhotoAltData::fromPayload(['loadedAt' => '1730000000', 'alts' => ['de' => 'Der Saal am Abend']], ['de']);

        self::assertCount(0, $this->validator()->validate($data));
        $command = $data->toCommand(7, 'photo-id');
        self::assertSame(['de' => 'Der Saal am Abend'], $command->alts);
        self::assertSame('1730000000', $command->loadedAt);
    }

    public function test_photo_alts_are_trimmed_before_the_length_rule(): void
    {
        // The island trims too (F-m7); a direct API call must not store or count the outer spaces either.
        $data = PhotoAltData::fromPayload(['loadedAt' => '1', 'alts' => ['de' => "  Der Saal\n ", 'en' => str_repeat('a', PhotoAltData::ALT_MAX)."  \t"]], ['de', 'en']);

        self::assertCount(0, $this->validator()->validate($data));
        self::assertSame(['de' => 'Der Saal', 'en' => str_repeat('a', PhotoAltData::ALT_MAX)], $data->toCommand(7, 'photo-id')->alts);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidAlts(): iterable
    {
        yield 'no token' => [['alts' => ['de' => 'x']], 'loadedAt'];
        yield 'token not a string' => [['loadedAt' => 1730000000, 'alts' => ['de' => 'x']], 'loadedAt'];
        yield 'token not digits' => [['loadedAt' => 'gestern', 'alts' => ['de' => 'x']], 'loadedAt'];
        yield 'no alts' => [['loadedAt' => '1'], 'alts'];
        yield 'alts a string' => [['loadedAt' => '1', 'alts' => 'x'], 'alts'];
        yield 'an alt not a string' => [['loadedAt' => '1', 'alts' => ['de' => ['x']]], 'alts[de]'];
        yield 'an alt with a NUL' => [['loadedAt' => '1', 'alts' => ['de' => "a\0b"]], 'alts[de]'];
        yield 'an alt with invalid UTF-8' => [['loadedAt' => '1', 'alts' => ['de' => "Saal \xff "]], 'alts[de]'];
        yield 'an alt too long' => [['loadedAt' => '1', 'alts' => ['de' => str_repeat('a', 701)]], 'alts[de]'];
        yield 'another locale' => [['loadedAt' => '1', 'alts' => ['fr' => 'x']], 'alts'];
    }

    /**
     * @param array<mixed> $payload
     */
    #[DataProvider('invalidAlts')]
    public function test_photo_alts_refuse(array $payload, string $path): void
    {
        $paths = [];
        foreach ($this->validator()->validate(PhotoAltData::fromPayload($payload, ['de'])) as $violation) {
            $paths[] = $violation->getPropertyPath();
            self::assertNotSame('', (string) $violation->getMessage());
        }

        self::assertContains($path, $paths);
    }

    public function test_a_document_upload_builds_its_command(): void
    {
        $form = $this->document($this->pdf('dokument.pdf'), ['de' => 'Preisliste']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(DocumentUploadData::class, $data);
        self::assertSame(['de' => 'Preisliste'], $data->toCommand(7)->titles);
    }

    public function test_a_document_upload_refuses_a_missing_or_wrong_file_and_a_missing_title(): void
    {
        self::assertArrayHasKey('file', self::errors($this->document(null, ['de' => 'Preisliste'])));

        $jpeg = TestImages::write($this->uploads, 'foto.pdf', TestImages::jpeg());
        self::assertArrayHasKey('file', self::errors($this->document(new UploadedFile($jpeg, 'foto.pdf', 'application/pdf', null, true), ['de' => 'Preisliste'])));

        self::assertArrayHasKey('titles.de', self::errors($this->document($this->pdf('dokument.pdf'), ['de' => ''])));
        self::assertArrayHasKey('titles.de', self::errors($this->document($this->pdf('dokument.pdf'), ['de' => "Preis\0liste"])));
    }

    public function test_a_document_above_15_mb_is_refused_without_naming_the_file(): void
    {
        $path = TestImages::write($this->uploads, 'gross.pdf', ContentFolder::pdf('Gross').str_repeat(' ', 15_000_001));
        $form = $this->document(new UploadedFile($path, "gro\xffss.pdf", 'application/pdf', null, true), ['de' => 'Preisliste']);

        $errors = self::errors($form);
        self::assertArrayHasKey('file', $errors);
        self::assertSame(['Das PDF ist zu gross, höchstens 15 MB.'], $errors['file']);
    }

    public function test_document_titles_need_the_token(): void
    {
        $form = $this->forms->create(DocumentTitleType::class);
        $form->submit(['titles' => ['de' => 'Preisliste']]);

        self::assertArrayHasKey('loadedAt', self::errors($form));

        foreach (["\xff", "1\x002"] as $tampered) {
            $form = $this->forms->create(DocumentTitleType::class);
            $form->submit(['loadedAt' => $tampered, 'titles' => ['de' => 'Preisliste']]);

            self::assertArrayHasKey('loadedAt', self::errors($form));
        }
    }

    public function test_a_review_builds_its_commands(): void
    {
        $form = $this->review(self::reviewPayload() + ['locale' => 'de']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(ReviewData::class, $data);
        $command = $data->toAddCommand(7);
        self::assertSame(['de', 'Lea', 'organiser_f', '2026-06', 'Ein schöner Tag.'], [$command->locale, $command->firstName, $command->role, $command->month, $command->text]);

        $form = $this->review(self::reviewPayload() + ['loadedAt' => '1730000000'], edit: true);
        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $data = $form->getData();
        self::assertInstanceOf(ReviewData::class, $data);
        self::assertSame('1730000000', $data->toChangeCommand(7, 'review-id')->loadedAt);
    }

    /**
     * @return iterable<string, array{array<string, string>, string, bool}>
     */
    public static function invalidReviews(): iterable
    {
        yield 'month 13' => [['month' => '2026-13'], 'month', false];
        yield 'month 00' => [['month' => '2026-00'], 'month', false];
        yield 'year 0' => [['month' => '0000-01'], 'month', false];
        yield 'a date' => [['month' => '2026-06-01'], 'month', false];
        yield 'invalid UTF-8 month' => [['month' => "2026-\xff"], 'month', false];
        yield 'no first name' => [['firstName' => ''], 'firstName', false];
        yield 'first name too long' => [['firstName' => str_repeat('a', 51)], 'firstName', false];
        yield 'text with a NUL' => [['text' => "a\0b"], 'text', false];
        yield 'text too long' => [['text' => str_repeat('a', 1001)], 'text', false];
        yield 'unknown role' => [['role' => 'critic'], 'role', false];
        yield 'another locale' => [['locale' => 'fr'], 'locale', false];
        yield 'locale with invalid UTF-8' => [['locale' => "\xff"], 'locale', false];
        yield 'locale with a NUL' => [['locale' => "d\0e"], 'locale', false];
        yield 'edit without token' => [[], 'loadedAt', true];
        yield 'token with invalid UTF-8' => [['loadedAt' => "\xff"], 'loadedAt', true];
        yield 'token with a NUL' => [['loadedAt' => "1\x002"], 'loadedAt', true];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('invalidReviews')]
    public function test_a_review_refuses(array $override, string $field, bool $edit): void
    {
        $form = $this->review(array_replace(self::reviewPayload() + ($edit ? [] : ['locale' => 'de']), $override), $edit);

        self::assertFalse($form->isValid());
        self::assertArrayHasKey($field, self::errors($form), (string) $form->getErrors(true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function roomPayload(): array
    {
        return [
            'names' => ['de' => 'Rittersaal'],
            'descriptions' => ['de' => 'Mit Kamin.'],
            'capacityMin' => 20,
            'capacityMax' => 120,
            'areaM2' => 180,
            'heightM' => '4,2',
            'floorMaterial' => 'parquet',
            'seating' => ['banquet' => ['min' => 40, 'max' => 100]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function reviewPayload(): array
    {
        return ['firstName' => 'Lea', 'role' => 'organiser_f', 'month' => '2026-06', 'text' => 'Ein schöner Tag.'];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return FormInterface<mixed>
     */
    private function room(array $payload, bool $withToken = false): FormInterface
    {
        $form = $this->forms->create(RoomType::class, new RoomData(), ['with_token' => $withToken]);
        $form->submit($payload);

        return $form;
    }

    /**
     * @param array<string, string> $titles
     *
     * @return FormInterface<mixed>
     */
    private function document(?UploadedFile $file, array $titles): FormInterface
    {
        $form = $this->forms->create(DocumentUploadType::class, new DocumentUploadData(), ['csrf_protection' => false]);
        $form->submit(['file' => $file, 'titles' => $titles]);

        return $form;
    }

    /**
     * @param array<string, string> $payload
     *
     * @return FormInterface<mixed>
     */
    private function review(array $payload, bool $edit = false): FormInterface
    {
        $form = $this->forms->create(ReviewType::class, new ReviewData(), ['edit' => $edit, 'csrf_protection' => false]);
        $form->submit($payload);

        return $form;
    }

    private function pdf(string $name): UploadedFile
    {
        return new UploadedFile(TestImages::write($this->uploads, uniqid().'.pdf', ContentFolder::pdf('Fiktive Preisliste')), $name, 'application/pdf', null, true);
    }

    private function validator(): ValidatorInterface
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator;
    }

    /**
     * Errors by the dotted path of the field they belong to ('' for the root).
     *
     * @param FormInterface<mixed> $form
     *
     * @return array<string, list<string>>
     */
    private static function errors(FormInterface $form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            \assert($error instanceof FormError);
            $path = [];
            for ($origin = $error->getOrigin(); null !== $origin && null !== $origin->getParent(); $origin = $origin->getParent()) {
                array_unshift($path, $origin->getName());
            }
            $errors[implode('.', $path)][] = $error->getMessage();
        }

        return $errors;
    }
}
