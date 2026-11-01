<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Venues\ValueObject\Tier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The deals of a company or contact, one statement each: the card's first rows with their total (D27, N13), and all of
 * a company's for its Deals tab. Open deals first, then the contract that ends last.
 */
final class DealCardQuery
{
    public const int CARD_ROWS = 5;

    private const string COLUMNS = <<<'SQL'
        SELECT d.id, d.name, d.stage_code, d.tier, d.amount_rappen, d.contract_end_on, v.name AS venue_name
        SQL;

    private const string ORDER = ' ORDER BY (s.is_won OR s.is_lost), d.contract_end_on DESC NULLS LAST, lower(d.name), d.id';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function forCompany(string $companyId): DealCard
    {
        return $this->card(' WHERE d.company_id = :id', $companyId);
    }

    public function forContact(string $contactId): DealCard
    {
        return $this->card(' JOIN crm_deal_contacts dc ON dc.deal_id = d.id AND dc.contact_id = :id', $contactId);
    }

    /**
     * @return list<DealCardRow>
     */
    public function allOfCompany(string $companyId): array
    {
        return array_map(self::row(...), $this->connection->fetchAllAssociative(
            self::COLUMNS.' '.self::from(' WHERE d.company_id = :id').self::ORDER,
            ['id' => $companyId],
        ));
    }

    private function card(string $condition, string $id): DealCard
    {
        $rows = $this->connection->fetchAllAssociative(
            self::COLUMNS.', COUNT(*) OVER () AS total '.self::from($condition).self::ORDER.' LIMIT :limit',
            ['id' => $id, 'limit' => self::CARD_ROWS],
            ['limit' => ParameterType::INTEGER],
        );

        return new DealCard(array_map(self::row(...), $rows), (int) ($rows[0]['total'] ?? 0));
    }

    /**
     * The condition is a join or a `WHERE`, so it goes last; a `WHERE` after further joins would not read.
     */
    private static function from(string $condition): string
    {
        return 'FROM crm_deals d JOIN crm_deal_stages s ON s.code = d.stage_code LEFT JOIN venues v ON v.id = d.venue_id'.$condition;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function row(array $row): DealCardRow
    {
        return new DealCardRow(
            (string) $row['id'],
            (string) $row['name'],
            (string) $row['stage_code'],
            null === $row['tier'] ? null : Tier::from((string) $row['tier']),
            null === $row['venue_name'] ? null : (string) $row['venue_name'],
            null === $row['amount_rappen'] ? null : (int) $row['amount_rappen'],
            null === $row['contract_end_on'] ? null : new \DateTimeImmutable((string) $row['contract_end_on']),
        );
    }
}
