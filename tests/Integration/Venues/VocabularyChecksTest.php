<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Admin\ValueObject\AdminRole;
use App\Admin\ValueObject\AuditActorType;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\ReviewerRole;
use App\Venues\ValueObject\TermGroup;
use App\Venues\ValueObject\Tier;
use App\Venues\ValueObject\VenueStatus;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every enum stored in a CHECK-constrained column has exactly the values of its CHECK's
 * IN list, in the same order, so neither can change without the other.
 */
final class VocabularyChecksTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, class-string<\BackedEnum>}>
     */
    public static function checks(): iterable
    {
        yield 'tier' => ['chk_venues_tier', Tier::class];
        yield 'status' => ['chk_venues_status', VenueStatus::class];
        yield 'own catering' => ['chk_venues_own_catering', OwnCatering::class];
        yield 'floor material' => ['chk_rooms_floor_material', FloorMaterial::class];
        yield 'reviewer role' => ['chk_venue_reviews_role', ReviewerRole::class];
        yield 'rating highlight' => ['chk_venue_rating_highlights_code', RatingHighlight::class];
        yield 'term group' => ['chk_taxonomy_terms_term_group', TermGroup::class];
        yield 'price level' => ['chk_venues_price_level', PriceLevel::class];
        yield 'party' => ['chk_venues_party', PartyAllowed::class];
        yield 'admin role' => ['chk_admin_users_role', AdminRole::class];
        yield 'audit actor type' => ['chk_admin_audit_log_actor_type', AuditActorType::class];
        yield 'portal venue access status' => ['chk_portal_venue_access_status', PortalVenueAccessStatus::class];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[DataProvider('checks')]
    public function test_check_lists_the_enum_values(string $constraint, string $enum): void
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $definition = $connection->fetchOne('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
        self::assertIsString($definition, $constraint);
        preg_match_all("/'([^']*)'::/", $definition, $matches);

        self::assertSame(array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()), $matches[1]);
    }
}
