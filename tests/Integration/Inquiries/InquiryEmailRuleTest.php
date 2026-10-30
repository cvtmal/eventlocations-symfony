<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inquiries;

use App\Factory\InquiryFactory;
use App\Inquiries\Form\InquiryData;
use App\Tests\Integration\Geo\ImportsTownDataset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * The form's e-mail rule against the real `chk_inquiries_email`. The form may refuse more than
 * the CHECK, never less: what passes the form must store, or the guest gets a 500 and the
 * database error logs the whole row. PCRE and Postgres disagree on `\s`, so the two regexes
 * cannot simply be compared.
 */
final class InquiryEmailRuleTest extends KernelTestCase
{
    use Factories;
    use ImportsTownDataset;

    private Connection $connection;
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

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
    public static function addresses(): iterable
    {
        yield 'plain' => ['anna@example.ch', true, true];
        yield 'plus and dots' => ['anna.muster+hochzeit@example.ch', true, true];
        yield 'apostrophe and subdomain' => ["o'neil_1@mail.example.co.uk", true, true];
        yield 'IDN domain' => ['anna@müller.ch', true, true];
        yield 'IDN domain of a real venue' => ['info@schüür.be', true, true];

        // Refused by both.
        yield 'ASCII space' => ['a b@example.ch', false, false];
        yield 'tab' => ["a\tb@example.ch", false, false];
        yield 'trailing newline' => ["anna@example.ch\n", false, false];
        yield 'next line control' => ["an\u{85}na@example.ch", false, false];
        yield 'em space in the local part' => ["an\u{2003}na@example.ch", false, false];
        yield 'em space in the domain' => ["anna@exam\u{2003}ple.ch", false, false];
        yield 'thin space in the domain' => ["anna@exam\u{2009}ple.ch", false, false];
        yield 'line separator in the local part' => ["an\u{2028}na@example.ch", false, false];
        yield 'paragraph separator in the domain' => ["anna@exam\u{2029}ple.ch", false, false];
        yield 'medium mathematical space' => ["an\u{205F}na@example.ch", false, false];
        yield 'ideographic space in the local part' => ["an\u{3000}na@example.ch", false, false];
        yield 'ideographic space in the domain' => ["anna@exam\u{3000}ple.ch", false, false];
        yield 'quoted space' => ['"a b"@x.ch', false, false];
        yield 'two at signs' => ['a@b@example.ch', false, false];
        yield 'no at sign' => ['anna.example.ch', false, false];
        yield 'no local part' => ['@example.ch', false, false];
        yield 'no domain' => ['anna@', false, false];

        // Stored safely, but it would fail later: the form is stricter on purpose.
        yield 'two dots: Address refuses it' => ['a..b@x.ch', false, true];
        yield 'umlaut in the local part: needs SMTPUTF8' => ['müller@example.ch', false, true];
        yield 'no-break space in the local part: not \s in Postgres' => ["an\u{A0}na@example.ch", false, true];
        yield 'no-break space in the domain' => ["anna@exam\u{A0}ple.ch", false, true];
        yield 'figure space in the domain' => ["anna@exam\u{2007}ple.ch", false, true];
        yield 'narrow no-break space in the domain' => ["anna@exam\u{202F}ple.ch", false, true];
        yield 'ogham space in the domain' => ["anna@exam\u{1680}ple.ch", false, true];
        yield 'zero-width space in the local part' => ["an\u{200B}na@example.ch", false, true];
        yield 'zero-width space in the domain' => ["anna@exam\u{200B}ple.ch", false, true];
    }

    #[DataProvider('addresses')]
    public function test_the_form_and_the_check_on_an_address(string $email, bool $form, bool $check): void
    {
        self::assertSame($form, $this->formAccepts($email), 'form');
        self::assertSame($check, $this->checkAccepts($email), 'CHECK');
        self::assertFalse($form && !$check, 'What passes the form must store.');
    }

    /**
     * Every code point, with the pattern read from the constraint itself: what the CHECK
     * refuses in either part of an address, the form refuses too.
     */
    public function test_the_form_refuses_every_character_the_check_refuses(): void
    {
        $pattern = $this->connection->fetchOne("SELECT substring(pg_get_constraintdef(oid) from '~ ''(.*)''::text') FROM pg_constraint WHERE conname = 'chk_inquiries_email'");
        self::assertIsString($pattern);

        foreach (['anna%s@example.ch', 'anna@exam%sple.ch'] as $address) {
            /** @var list<int> $refused */
            $refused = $this->connection->fetchFirstColumn(
                'SELECT cp FROM generate_series(1, 1114111) cp WHERE (cp < 55296 OR cp > 57343) AND format(?, chr(cp)) !~ ?',
                [$address, $pattern],
            );

            self::assertContains(0x2003, $refused);
            self::assertContains(0x20, $refused);
            foreach ($refused as $codePoint) {
                self::assertFalse($this->formAccepts(\sprintf($address, mb_chr($codePoint, 'UTF-8'))), \sprintf('U+%04X in %s', $codePoint, $address));
            }
        }
    }

    private function formAccepts(string $email): bool
    {
        return 0 === $this->validator->validatePropertyValue(InquiryData::class, 'email', $email)->count();
    }

    private function checkAccepts(string $email): bool
    {
        $id = InquiryFactory::createOne()->id();
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('UPDATE inquiries SET email = ? WHERE id = ?', [$email, $id]);

            return true;
        } catch (DriverException $exception) {
            self::assertStringContainsString('chk_inquiries_email', $exception->getMessage());

            return false;
        } finally {
            $this->connection->rollBack();
        }
    }
}
