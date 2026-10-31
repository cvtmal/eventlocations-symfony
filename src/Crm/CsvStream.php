<?php

declare(strict_types=1);

namespace App\Crm;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CRM's CSV exports (CRM-6, D20) for Swiss Excel: `;` between cells, a UTF-8 BOM, RFC 4180
 * quoting. `fputcsv()`'s escape character is passed as `''`: the default `\` breaks RFC 4180 (a `\"`
 * in a cell is written without doubling the quote) and passing nothing is deprecated since PHP 8.4.
 * Every cell goes through `cell()`, so a value staff typed cannot run as a formula (OWASP CSV
 * injection), an E.164 phone number included (P13).
 */
final readonly class CsvStream
{
    public const string SEPARATOR = ';';
    public const string ENCLOSURE = '"';
    public const string ESCAPE = '';
    public const string EOL = "\r\n";
    public const string BOM = "\u{FEFF}";
    public const string CONTENT_TYPE = 'text/csv; charset=UTF-8';

    /** Leads a spreadsheet reads as a formula, or as the start of one after a stripped control character. */
    private const array FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠'];

    private function __construct()
    {
    }

    /**
     * @param list<string>                $header
     * @param iterable<list<string|null>> $rows          produced inside the stream, so a large list never sits in memory as CSV
     * @param string                      $failureNotice the file's last line when the rows fail after the 200 went out
     */
    public static function response(string $filename, array $header, iterable $rows, string $failureNotice, LoggerInterface $logger): StreamedResponse
    {
        $generator = (static fn (): \Generator => yield from $rows)();
        // Runs the query up to the first row, so a failure here is still a real error page.
        $started = $generator->valid() ? $generator : [];

        $response = new StreamedResponse(static function () use ($filename, $header, $started, $failureNotice, $logger): void {
            $output = fopen('php://output', 'w');
            if (false === $output) {
                throw new \RuntimeException('Cannot open php://output.');
            }
            try {
                self::write($output, $header, $started);
            } catch (\Throwable $failure) {
                self::line($output, [$failureNotice]);
                $logger->error('CSV export {export}: stopped ({exception_class} at {file}:{line}).', [
                    'export' => $filename,
                    'exception_class' => $failure::class,
                    'file' => $failure->getFile(),
                    'line' => $failure->getLine(),
                ]);
            }
            fclose($output);
        });
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename));

        return $response;
    }

    /**
     * Writes the BOM, the header and the rows to an open stream.
     *
     * @param resource                    $stream
     * @param list<string>                $header
     * @param iterable<list<string|null>> $rows
     */
    public static function write($stream, array $header, iterable $rows): void
    {
        fwrite($stream, self::BOM);
        self::line($stream, $header);
        foreach ($rows as $row) {
            self::line($stream, $row);
        }
    }

    /**
     * One record, every cell through `cell()`.
     *
     * @param resource          $stream
     * @param list<string|null> $cells
     */
    public static function line($stream, array $cells): void
    {
        fputcsv($stream, array_map(self::cell(...), $cells), self::SEPARATOR, self::ENCLOSURE, self::ESCAPE, self::EOL);
    }

    /**
     * A value as a cell: null is empty, a leading formula character gets a `'` so the spreadsheet
     * shows the text instead of evaluating it.
     */
    public static function cell(?string $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return \in_array(mb_substr($value, 0, 1), self::FORMULA_LEADS, true) ? "'".$value : $value;
    }
}
