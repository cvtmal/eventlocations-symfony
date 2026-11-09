<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Inquiries\ValueObject\BriefRules;
use App\Shared\Translation\TranslationJoin;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The venues of a Merkliste or a result list that a brief can go to, in the order asked. A venue that cannot get any
 * inquiry (unpublished, gone, no inquiry address) is left out; one that opted out of briefs or reached today's cap
 * comes back with `offered = false`. `SubmitInquiryBriefHandler` checks the same rules again under a lock.
 */
final readonly class BriefCandidatesQuery
{
    public function __construct(
        private Connection $connection,
        private TranslationJoin $translationJoin,
    ) {
    }

    /**
     * @param list<int> $numbers
     *
     * @return list<BriefCandidate>
     */
    public function forNumbers(array $numbers, string $locale, \DateTimeImmutable $now): array
    {
        if ([] === $numbers) {
            return [];
        }
        $translation = $this->translationJoin->localized('venue_translations', 'venue_id', 'v.id', 't');
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
                SELECT v.id, v.number, v.name, t.slug, tw.name AS town,
                       v.accepts_multi_inquiries AND (
                           SELECT count(*) FROM inquiries i
                           WHERE i.venue_id = v.id AND i.brief_id IS NOT NULL AND i.created_at >= :day_start
                       ) < :cap AS offered
                FROM venues v
                LEFT JOIN towns tw ON tw.id = v.town_id
                {$translation}
                WHERE v.number IN (:numbers) AND v.status = 'published' AND v.inquiry_email IS NOT NULL AND t.slug IS NOT NULL
                SQL,
            [
                'numbers' => $numbers,
                'day_start' => BriefRules::dayStart($now)->format(\DATE_ATOM),
                'cap' => BriefRules::DAILY_CAP_PER_VENUE,
            ] + $this->translationJoin->parameters($locale),
            ['numbers' => ArrayParameterType::INTEGER],
        );

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[(int) $row['number']] = new BriefCandidate(
                (string) $row['id'],
                (int) $row['number'],
                (string) $row['name'],
                (string) $row['slug'],
                null === $row['town'] ? null : (string) $row['town'],
                (bool) $row['offered'],
            );
        }

        return array_values(array_filter(array_map(static fn (int $number): ?BriefCandidate => $candidates[$number] ?? null, $numbers)));
    }
}
