<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cms;

use App\Cms\Form\PhoneNumberTransformer;
use App\Cms\Form\VenueContactData;
use App\Factory\VenueFactory;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The contact card's rules against the real CHECKs (P16, D18), as `InquiryEmailRuleTest` does for the
 * inquiry form: the form may refuse more than a CHECK, never less, because what passes the form must store.
 */
final class VenueContactRulesTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;
    private string $venueId;

    protected function setUp(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->beginTransaction();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        $this->validator = $validator;
        $this->venueId = VenueFactory::createOne()->id();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool, bool}> the address, the form, the CHECK
     */
    public static function emails(): iterable
    {
        yield 'plain' => ['info@seeblick.ch', true, true];
        yield 'plus and dots' => ['events.team+hochzeit@seeblick.example.ch', true, true];
        yield 'IDN domain' => ['info@schüür.be', true, true];

        // Refused by both.
        yield 'no dot in the domain' => ['info@localhost', false, false];
        yield 'ASCII space' => ['a b@example.ch', false, false];
        yield 'tab' => ["a\tb@example.ch", false, false];
        yield 'trailing newline' => ["info@example.ch\n", false, false];
        yield 'em space in the domain' => ["info@exam\u{2003}ple.ch", false, false];
        yield 'two at signs' => ['a@b@example.ch', false, false];
        yield 'no local part' => ['@example.ch', false, false];

        // Stored safely, refused on purpose.
        yield 'dot at the end of the domain' => ['info@example.', false, false];
        yield 'two dots' => ['a..b@example.ch', false, true];
        yield 'umlaut in the local part' => ['müller@example.ch', false, true];
        yield 'no-break space in the domain' => ["info@exam\u{A0}ple.ch", false, true];
        yield 'zero-width space in the domain' => ["info@exam\u{200B}ple.ch", false, true];
    }

    #[DataProvider('emails')]
    public function test_an_inquiry_address_against_the_check(string $email, bool $form, bool $check): void
    {
        self::assertSame($form, $this->formAccepts('inquiryEmail', $email), 'form');
        self::assertSame($check, $this->checkAccepts('inquiry_email', $email), 'CHECK');
    }

    /**
     * Every code point the CHECK refuses in either part of an address, the form refuses too.
     */
    public function test_the_form_refuses_every_character_the_check_refuses(): void
    {
        $pattern = $this->connection->fetchOne("SELECT substring(pg_get_constraintdef(oid) from '~ ''(.*)''::text') FROM pg_constraint WHERE conname = 'chk_venues_inquiry_email'");
        self::assertIsString($pattern);

        foreach (['info%s@example.ch', 'info@exam%sple.ch'] as $address) {
            /** @var list<int> $refused */
            $refused = $this->connection->fetchFirstColumn(
                'SELECT cp FROM generate_series(1, 1114111) cp WHERE (cp < 55296 OR cp > 57343) AND format(?, chr(cp)) !~ ?',
                [$address, $pattern],
            );

            self::assertContains(0x20, $refused);
            foreach ($refused as $codePoint) {
                self::assertFalse($this->formAccepts('inquiryEmail', \sprintf($address, mb_chr($codePoint, 'UTF-8'))), \sprintf('U+%04X in %s', $codePoint, $address));
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, bool, bool}> field, column, value, the form, the CHECK
     */
    public static function links(): iterable
    {
        yield 'website https' => ['website', 'website', 'https://seeblick.ch', true, true];
        yield 'website http' => ['website', 'website', 'http://seeblick.ch', true, true];
        yield 'website ftp' => ['website', 'website', 'ftp://seeblick.ch', false, false];
        yield 'website upper-case scheme' => ['website', 'website', 'HTTPS://seeblick.ch', false, false];
        yield 'website with a leading space' => ['website', 'website', ' https://seeblick.ch', false, false];
        yield 'website of 500 characters' => ['website', 'website', 'https://seeblick.ch/'.str_repeat('a', 480), true, true];
        yield 'website of 501 characters' => ['website', 'website', 'https://seeblick.ch/'.str_repeat('a', 481), false, false];
        yield 'Instagram https' => ['instagram', 'instagram', 'https://www.instagram.com/seeblick', true, true];
        yield 'Instagram http' => ['instagram', 'instagram', 'http://www.instagram.com/seeblick', false, false];
        yield 'Instagram of 501 characters' => ['instagram', 'instagram', 'https://instagram.com/'.str_repeat('a', 479), false, false];
    }

    #[DataProvider('links')]
    public function test_a_link_against_the_check(string $field, string $column, string $value, bool $form, bool $check): void
    {
        self::assertSame($form, $this->formAccepts($field, $value), 'form');
        self::assertSame($check, $this->checkAccepts($column, $value), 'CHECK');
    }

    /**
     * @return iterable<string, array{string, ?string}> typed, stored (null: refused by the form)
     */
    public static function phones(): iterable
    {
        yield 'Swiss national' => ['044 123 45 67', '+41441234567'];
        yield 'free number' => ['0800 123 456', '+41800123456'];
        yield 'foreign' => ['+49 30 1234567', '+49301234567'];
        yield 'longest E.164' => ['+123 456 789 012 345', '+123456789012345'];
        yield 'one digit too long' => ['+123 456 789 012 3456', null];
        yield 'too short' => ['+41 12 34', null];
        yield 'letters' => ['044 CALL NOW', null];
        yield 'trailing newline' => ["044 123 45 67\n", null];
    }

    #[DataProvider('phones')]
    public function test_a_phone_that_passes_the_form_stores(string $typed, ?string $stored): void
    {
        try {
            $e164 = new PhoneNumberTransformer()->reverseTransform($typed);
        } catch (TransformationFailedException) {
            $e164 = null;
        }

        self::assertSame($stored, $e164);
        if (null !== $e164) {
            self::assertTrue($this->checkAccepts('phone', $e164));
        }
    }

    private function formAccepts(string $property, string $value): bool
    {
        return 0 === $this->validator->validatePropertyValue(VenueContactData::class, $property, $value)->count();
    }

    private function checkAccepts(string $column, string $value): bool
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(\sprintf('UPDATE venues SET %s = ? WHERE id = ?', $column), [$value, $this->venueId]);

            return true;
        } catch (DriverException $exception) {
            self::assertMatchesRegularExpression('/chk_venues_'.$column.'|value too long/', $exception->getMessage());

            return false;
        } finally {
            $this->connection->rollBack();
        }
    }
}
