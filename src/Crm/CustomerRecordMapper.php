<?php

declare(strict_types=1);

namespace App\Crm;

use App\Crm\Form\PropertyConstraints;
use App\Crm\ValueObject\CompanyProperty;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\CustomerNoteField;
use App\Crm\ValueObject\CustomerRecord;
use App\Crm\ValueObject\CustomerRow;
use App\Crm\ValueObject\SheetText;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Turns a customer of the sheet into what the CRM stores (#48 R6–R8): the field rules of the inline editor
 * (`PropertyConstraints`), so an imported value is one staff could have typed. A cell that does not fit is
 * a warning, never a lost row: the field stays empty and the cell goes into the note. Pure: the validator
 * is built here, not taken from the container.
 */
final class CustomerRecordMapper
{
    /** The last name of a contact the sheet names no last name for (owner, OD-1): stored data, so no catalogue key. */
    public const string UNKNOWN_LAST_NAME = 'unbekannt';

    /** "04.01.2024, 11:38", "24.07.2023: 10:08", "22.08.2024". */
    private const string DATE = '/(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s*[,:]\s*(\d{1,2}):(\d{2}))?/';

    /** What may stand between two dates of one cell. */
    private const string DATE_SEPARATORS = '/[^\s,&+:]/u';

    private const string ZONE = 'Europe/Zurich';

    private const string EMAIL_SEPARATORS = '/[\s,;\/&]+/u';

    private const string PHONE_SEPARATORS = '/\s*[&;,]\s*|\s+(?:und|oder)\s+/iu';

    /** Query parameters that only track a click; `utm_*` besides. */
    private const array TRACKING = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', '_gl', 'mc_cid', 'mc_eid'];

    private ValidatorInterface $validator;

    public function __construct()
    {
        $this->validator = Validation::createValidator();
    }

    /**
     * @param list<CustomerRow> $duplicates rows of the same place, merged into $primary: their dates become
     *                                      the company's mails, and each adds a note block
     */
    public function map(string $finalName, CustomerRow $primary, array $duplicates): CustomerRecord
    {
        $website = $this->website($primary->website);
        $email = $this->email($primary->email);
        $phone = $this->phone($primary->phone);

        $dates = [];
        $blocks = [];
        $warnings = [];
        foreach ([$primary, ...$duplicates] as $index => $row) {
            $fields = 0 === $index
                ? [CustomerNoteField::Email->value => $email, CustomerNoteField::Phone->value => $phone, CustomerNoteField::Website->value => $website]
                : [
                    CustomerNoteField::Email->value => self::merged($this->email($row->email), $email[0], $row->email),
                    CustomerNoteField::Phone->value => self::merged($this->phone($row->phone), $phone[0], $row->phone),
                    CustomerNoteField::Website->value => self::merged($this->website($row->website), $website[0], $row->website),
                ];
            [$rowDates, $mailNote, $mailWarning] = $this->mailDates($row->mailSent);
            foreach ($rowDates as $date) {
                $dates[$date->getTimestamp()] = $date;
            }

            if ($row->region->isUnrecognised()) {
                $warnings[] = ['row' => $row->number, 'field' => CustomerNoteField::Region->value, 'reason' => 'unknown region'];
            }
            foreach ($fields as $field => [, , $warning]) {
                if (null !== $warning) {
                    $warnings[] = ['row' => $row->number, 'field' => $field, 'reason' => $warning];
                }
            }
            if (null !== $mailWarning) {
                $warnings[] = ['row' => $row->number, 'field' => CustomerNoteField::MailSent->value, 'reason' => $mailWarning];
            }

            $blocks[] = ['row' => $row->number, 'lines' => self::lines([
                [CustomerNoteField::Status, $row->status],
                [CustomerNoteField::Salutation, $row->salutation],
                [CustomerNoteField::Region, $row->region->label()],
                [CustomerNoteField::Instagram, $row->instagram],
                [CustomerNoteField::Note, $row->note],
                [CustomerNoteField::Email, $fields[CustomerNoteField::Email->value][1]],
                [CustomerNoteField::Phone, $fields[CustomerNoteField::Phone->value][1]],
                [CustomerNoteField::Website, $fields[CustomerNoteField::Website->value][1]],
                [CustomerNoteField::MailSent, $mailNote],
            ])];
        }
        ksort($dates);

        return new CustomerRecord(
            name: $finalName,
            website: $website[0],
            email: $email[0],
            phone: $phone[0],
            firstName: $primary->firstName,
            lastName: $primary->lastName ?? self::UNKNOWN_LAST_NAME,
            mailDates: array_values($dates),
            noteBlocks: $blocks,
            rows: array_map(static fn (CustomerRow $row): int => $row->number, [$primary, ...$duplicates]),
            warnings: $warnings,
        );
    }

    /**
     * Whitespace removed, `https://` when the scheme is missing, click tracking dropped, then the editor's rule.
     *
     * @return array{?string, ?string, ?string} the value, the note, the warning
     */
    private function website(?string $cell): array
    {
        if (null === $cell) {
            return [null, null, null];
        }
        $url = self::canonicalWebsite($cell);
        // The editor's rule takes "https://KeineWebsite"; a host without a dot is no website.
        $host = SheetText::host($url);
        if (null === $host || !str_contains($host, '.') || !$this->fits($url, CompanyProperty::Website)) {
            return [null, $cell, 'invalid website'];
        }

        return [$url, null, null];
    }

    /**
     * The first part of the cell the editor would take; the other parts go into the note.
     *
     * @return array{?string, ?string, ?string}
     */
    private function email(?string $cell): array
    {
        if (null === $cell) {
            return [null, null, null];
        }
        $parts = preg_split(self::EMAIL_SEPARATORS, $cell, -1, \PREG_SPLIT_NO_EMPTY);
        \assert(\is_array($parts));
        foreach ($parts as $index => $part) {
            $address = ContactEmail::normalise($part);
            if (null !== $address && $this->fits($address, CompanyProperty::Email)) {
                unset($parts[$index]);

                return [$address, [] === $parts ? null : implode(', ', $parts), null];
            }
        }

        return [null, $cell, 'invalid email'];
    }

    /**
     * The cell when the editor takes it, else its first piece that fits; the whole cell goes into the note
     * whenever anything was dropped.
     *
     * @return array{?string, ?string, ?string}
     */
    private function phone(?string $cell): array
    {
        if (null === $cell) {
            return [null, null, null];
        }
        if ($this->fits($cell, CompanyProperty::Phone)) {
            return [$cell, null, null];
        }
        $pieces = preg_split(self::PHONE_SEPARATORS, $cell, -1, \PREG_SPLIT_NO_EMPTY);
        \assert(\is_array($pieces));
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ('' !== $piece && $this->fits($piece, CompanyProperty::Phone)) {
                return [$piece, $cell, null];
            }
        }

        return [null, $cell, 'invalid phone'];
    }

    /**
     * Every date of the cell, at its time or 00:00, in Zurich. Text beyond the dates and their separators,
     * or an impossible date, puts the whole cell into the note.
     *
     * @return array{list<\DateTimeImmutable>, ?string, ?string} the dates, the note, the warning
     */
    private function mailDates(?string $cell): array
    {
        if (null === $cell) {
            return [[], null, null];
        }
        preg_match_all(self::DATE, $cell, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        $dates = [];
        $impossible = false;
        foreach ($matches as $match) {
            [$day, $month, $year] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            $hour = (int) ($match[4] ?? 0);
            $minute = (int) ($match[5] ?? 0);
            // The years of `ActivityDate`: before 1894 Zurich's offset has seconds, which a timestamp(0) loses.
            if (!checkdate($month, $day, $year) || $year < 1900 || $year > 2099 || $hour > 23 || $minute > 59) {
                $impossible = true;
                continue;
            }
            $dates[] = new \DateTimeImmutable(\sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute), new \DateTimeZone(self::ZONE));
        }
        $leftover = 1 === preg_match(self::DATE_SEPARATORS, (string) preg_replace(self::DATE, '', $cell));

        return [$dates, $impossible || $leftover ? $cell : null, $impossible ? 'impossible date' : null];
    }

    /**
     * A merged row's cell is kept in its note unless it holds what the company already has.
     *
     * @param array{?string, ?string, ?string} $parsed
     *
     * @return array{?string, ?string, ?string}
     */
    private static function merged(array $parsed, ?string $kept, ?string $cell): array
    {
        if (null !== $parsed[0] && $parsed[0] !== $kept) {
            return [$parsed[0], $cell, null];
        }

        return $parsed;
    }

    private static function canonicalWebsite(string $cell): string
    {
        $url = (string) preg_replace('/\s+/u', '', $cell);
        if (1 === preg_match('~\A(https?)://~i', $url, $scheme)) {
            $url = strtolower($scheme[1]).substr($url, \strlen($scheme[1]));
        } elseif (1 !== preg_match('~\A[a-z][a-z0-9+.\-]*://~i', $url)) {
            $url = 'https://'.$url;
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if (false !== $hash) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $query = strpos($url, '?');
        if (false !== $query) {
            $kept = array_filter(
                explode('&', substr($url, $query + 1)),
                static fn (string $pair): bool => '' !== $pair && !self::isTracking($pair),
            );
            $url = substr($url, 0, $query).([] === $kept ? '' : '?'.implode('&', $kept));
        }

        return $url.$fragment;
    }

    private static function isTracking(string $pair): bool
    {
        $name = strtolower(urldecode(explode('=', $pair, 2)[0]));

        return str_starts_with($name, 'utm_') || \in_array($name, self::TRACKING, true);
    }

    private function fits(string $value, CompanyProperty $property): bool
    {
        return 0 === \count($this->validator->validate($value, PropertyConstraints::company($property)));
    }

    /**
     * @param list<array{CustomerNoteField, ?string}> $lines
     *
     * @return list<array{CustomerNoteField, string}>
     */
    private static function lines(array $lines): array
    {
        $kept = [];
        foreach ($lines as [$field, $value]) {
            if (null !== $value && '' !== $value) {
                $kept[] = [$field, $value];
            }
        }

        return $kept;
    }
}
