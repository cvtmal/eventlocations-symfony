<?php

declare(strict_types=1);

namespace App\Inquiries\ReadModel;

use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What the inquiry form offers for "Anlass" and "Catering", read from the venue vocabulary (F3), so an inquiry speaks
 * the words of the detail page and the filters: every `suitable_for` term is an event type, and the catering wish is
 * one of the two "who caters" terms of the `catering` group, the one catering source (D33). Loaded once per request
 * (the form and the handler both ask); `kernel.reset` by autoconfiguration.
 */
final class InquiryVocabularyQuery implements ResetInterface
{
    /** The catering terms that answer "who caters?"; their order is the form's. The DB CHECK lists the same codes. */
    public const array CATERING_WISHES = ['in_house', 'external_allowed'];

    /** @var array{event_types: list<Term>, catering_wishes: list<Term>}|null */
    private ?array $terms = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<Term> the `suitable_for` terms by `position`
     */
    public function eventTypes(): array
    {
        return $this->load()['event_types'];
    }

    /**
     * @return list<Term> the catering wishes in `CATERING_WISHES` order, those the vocabulary has
     */
    public function cateringWishes(): array
    {
        return $this->load()['catering_wishes'];
    }

    public function isEventType(string $code): bool
    {
        return \in_array($code, array_map(static fn (Term $term): string => $term->code, $this->eventTypes()), true);
    }

    public function isCateringWish(string $code): bool
    {
        return \in_array($code, array_map(static fn (Term $term): string => $term->code, $this->cateringWishes()), true);
    }

    public function reset(): void
    {
        $this->terms = null;
    }

    /**
     * @return array{event_types: list<Term>, catering_wishes: list<Term>}
     */
    private function load(): array
    {
        if (null !== $this->terms) {
            return $this->terms;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT term_group, code FROM taxonomy_terms WHERE term_group = :event OR (term_group = :catering AND code IN (:wishes)) ORDER BY position',
            ['event' => TermGroup::SuitableFor->value, 'catering' => TermGroup::Catering->value, 'wishes' => self::CATERING_WISHES],
            ['wishes' => ArrayParameterType::STRING],
        );

        $eventTypes = [];
        $catering = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['term_group']) && \is_string($row['code']));
            if (TermGroup::SuitableFor->value === $row['term_group']) {
                $eventTypes[] = new Term(TermGroup::SuitableFor, $row['code']);
            } else {
                $catering[$row['code']] = new Term(TermGroup::Catering, $row['code']);
            }
        }

        $wishes = [];
        foreach (self::CATERING_WISHES as $code) {
            if (isset($catering[$code])) {
                $wishes[] = $catering[$code];
            }
        }

        return $this->terms = ['event_types' => $eventTypes, 'catering_wishes' => $wishes];
    }
}
