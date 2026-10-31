<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crm;

use App\Crm\CsvStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV rules of the CRM exports (D20): `fputcsv()` with escape `''` quotes as RFC 4180 does, and
 * a cell that a spreadsheet would read as a formula is neutralised.
 */
final class CsvStreamTest extends TestCase
{
    public function test_a_hard_cell_is_rfc_4180_quoted_and_round_trips(): void
    {
        $cell = "Er sagte \\\"Ja\\\"; \"gut\"\nZeile 2";

        $line = $this->line([$cell, 'plain']);

        // Every quote doubled, the backslash before it included: the default escape `\` would write
        // `\"` undoubled, and an RFC 4180 reader would end the cell there.
        self::assertSame("\"Er sagte \\\"\"Ja\\\"\"; \"\"gut\"\"\nZeile 2\";plain\r\n", $line);
        self::assertSame([$cell, 'plain'], str_getcsv(substr($line, 0, -2), CsvStream::SEPARATOR, CsvStream::ENCLOSURE, CsvStream::ESCAPE));
    }

    public function test_a_backslash_stays_literal(): void
    {
        $cell = 'C:\\Ordner\\';

        $line = $this->line([$cell, 'next']);

        self::assertSame("C:\\Ordner\\;next\r\n", $line);
        self::assertSame([$cell, 'next'], str_getcsv(substr($line, 0, -2), CsvStream::SEPARATOR, CsvStream::ENCLOSURE, CsvStream::ESCAPE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formulaLeads(): iterable
    {
        yield 'equals' => ['=HYPERLINK("https://example.test")'];
        yield 'plus' => ['+41 44 123 45 67'];
        yield 'minus' => ['-2+3'];
        yield 'at' => ['@SUM(A1:A2)'];
        yield 'tab' => ["\t=1+1"];
        yield 'carriage return' => ["\r=1+1"];
        yield 'line feed' => ["\n=1+1"];
        yield 'full-width equals' => ['＝1+1'];
        yield 'full-width plus' => ['＋1+1'];
        yield 'full-width minus' => ['－1+1'];
        yield 'full-width at' => ['＠SUM(A1)'];
    }

    #[DataProvider('formulaLeads')]
    public function test_a_formula_lead_is_neutralised(string $value): void
    {
        self::assertSame("'".$value, CsvStream::cell($value));

        $line = $this->line([$value]);
        self::assertSame(["'".$value], str_getcsv(substr($line, 0, -2), CsvStream::SEPARATOR, CsvStream::ENCLOSURE, CsvStream::ESCAPE));
    }

    public function test_a_plain_value_is_unchanged(): void
    {
        self::assertSame('Seeblick Events GmbH', CsvStream::cell('Seeblick Events GmbH'));
        self::assertSame('Zürich = schön', CsvStream::cell('Zürich = schön'));
        self::assertSame('12', CsvStream::cell('12'));
        self::assertSame("'leading quote", CsvStream::cell("'leading quote"));
        self::assertSame('', CsvStream::cell(''));
        self::assertSame('', CsvStream::cell(null));
    }

    public function test_the_stream_starts_with_a_bom_and_triggers_no_deprecation(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        });
        try {
            $stream = $this->memory();
            CsvStream::write($stream, ['Firma', 'Telefon'], [['=cmd', null], ['Alpenhof AG', '+41441234567']]);
            rewind($stream);
            $csv = (string) stream_get_contents($stream);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $notices);
        self::assertSame("\u{FEFF}Firma;Telefon\r\n'=cmd;\r\n\"Alpenhof AG\";'+41441234567\r\n", $csv);
    }

    public function test_the_response_is_a_csv_attachment(): void
    {
        $response = CsvStream::response('firmen-2026-10-31.csv', ['Firma'], [['Alpenhof AG']], 'Abgebrochen', new NullLogger());

        self::assertSame(CsvStream::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=firmen-2026-10-31.csv', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        self::assertSame("\u{FEFF}Firma\r\n\"Alpenhof AG\"\r\n", ob_get_clean());
    }

    public function test_an_empty_list_is_the_header_alone(): void
    {
        self::assertSame("\u{FEFF}Firma\r\n", $this->sent(CsvStream::response('leer.csv', ['Firma'], (static function (): \Generator {
            yield from [];
        })(), 'Abgebrochen', new NullLogger())));
    }

    public function test_a_failure_before_the_first_row_comes_before_the_response(): void
    {
        $this->expectExceptionMessage('database down');

        CsvStream::response('firmen.csv', ['Firma'], (static function (): \Generator {
            yield from self::rowsOfADownDatabase();
        })(), 'Abgebrochen', new NullLogger());
    }

    public function test_a_failure_inside_the_stream_ends_the_file_with_a_visible_line_and_a_log(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level.' '.$message.' '.json_encode(array_keys($context));
            }
        };

        $content = $this->sent(CsvStream::response('firmen.csv', ['Firma'], (static function (): \Generator {
            yield ['Alpenhof'];
            throw new \RuntimeException('secret@example.test');
        })(), 'Export abgebrochen', $logger));

        self::assertSame("\u{FEFF}Firma\r\nAlpenhof\r\n\"Export abgebrochen\"\r\n", $content);
        self::assertSame(['error CSV export {export}: stopped ({exception_class} at {file}:{line}). ["export","exception_class","file","line"]'], $logger->lines);
    }

    /**
     * @return list<list<string>>
     */
    private static function rowsOfADownDatabase(): array
    {
        throw new \RuntimeException('database down');
    }

    private function sent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @param list<string|null> $cells
     */
    private function line(array $cells): string
    {
        $stream = $this->memory();
        CsvStream::line($stream, $cells);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * @return resource
     */
    private function memory()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);

        return $stream;
    }
}
