<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Validator;

use App\Shared\Validator\CleanText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class CleanTextTest extends TestCase
{
    /**
     * @return iterable<string, array{?string}>
     */
    public static function cleanValues(): iterable
    {
        yield 'plain text' => ['Schloss Au'];
        yield 'umlauts' => ['Zürich, Genève'];
        yield 'newlines' => ["Erster Absatz\n\nZweiter Absatz\r\n"];
        yield 'empty' => [''];
        yield 'null' => [null];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dirtyValues(): iterable
    {
        yield 'NUL inside' => ["a\0b"];
        yield 'NUL at the end' => ["a\0"];
        yield 'invalid UTF-8' => ["\xff"];
        yield 'truncated sequence' => ["Z\xc3"];
    }

    #[DataProvider('cleanValues')]
    public function test_clean_text_passes(?string $value): void
    {
        self::assertCount(0, Validation::createValidator()->validate($value, new CleanText()));
    }

    #[DataProvider('dirtyValues')]
    public function test_a_nul_or_invalid_utf8_fails_with_one_message(string $value): void
    {
        $violations = Validation::createValidator()->validate($value, new CleanText());

        self::assertCount(1, $violations);
        $violation = $violations->get(0);
        self::assertSame('shared.text.invalid', $violation->getMessageTemplate());
        self::assertSame(CleanText::INVALID, $violation->getCode());
        self::assertSame([], $violation->getParameters());
    }
}
