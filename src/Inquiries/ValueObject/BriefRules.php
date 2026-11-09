<?php

declare(strict_types=1);

namespace App\Inquiries\ValueObject;

/**
 * The limits of a brief to several venues (F13, D17). One line each, so a change is one edit.
 *
 * `DAILY_CAP_PER_VENUE` counts the children a venue got from briefs on the Zurich calendar day of the request; single
 * inquiries never count and are never capped. `MAX_CANDIDATES` bounds what the request page offers from a Merkliste
 * or a result list.
 */
final readonly class BriefRules
{
    public const int MIN_VENUES = 2;
    public const int MAX_VENUES = 5;
    public const int DAILY_CAP_PER_VENUE = 5;
    public const int MAX_CANDIDATES = 20;
    public const string ZONE = 'Europe/Zurich';

    /**
     * The start of the Zurich calendar day of `$now`, from which the daily cap counts.
     */
    public static function dayStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone(self::ZONE))->setTime(0, 0);
    }

    /**
     * The venue numbers of a `nr` query value: a comma list or a list of strings, in order, unique, at most 100 (the
     * Merkliste's size); anything that is no positive number is dropped.
     *
     * @return list<int>
     */
    public static function numbers(mixed $value): array
    {
        $parts = \is_array($value) ? $value : explode(',', \is_string($value) ? $value : '');
        $numbers = [];
        foreach ($parts as $part) {
            if (!\is_string($part) || 1 !== preg_match('/^[1-9][0-9]{0,8}$/', trim($part))) {
                continue;
            }
            $numbers[(int) trim($part)] = true;
            if (\count($numbers) >= 100) {
                break;
            }
        }

        return array_keys($numbers);
    }
}
