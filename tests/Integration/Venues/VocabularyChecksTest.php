<?php

declare(strict_types=1);

namespace App\Tests\Integration\Venues;

use App\Admin\ValueObject\AdminRole;
use App\Admin\ValueObject\AuditActorType;
use App\Portal\ValueObject\PortalVenueAccessStatus;
use App\Portal\ValueObject\SubmissionStatus;
use App\Venues\ValueObject\CandidateOrigin;
use App\Venues\ValueObject\CandidateStatus;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\GrantEvidence;
use App\Venues\ValueObject\GrantorType;
use App\Venues\ValueObject\ModerationDecision;
use App\Venues\ValueObject\ModerationKind;
use App\Venues\ValueObject\ModerationStatus;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\SourceStatus;
use App\Venues\ValueObject\SourceType;
use App\Venues\ValueObject\TermGroup;
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
        yield 'status' => ['chk_venues_status', VenueStatus::class];
        yield 'own catering' => ['chk_venues_own_catering', OwnCatering::class];
        yield 'floor material' => ['chk_rooms_floor_material', FloorMaterial::class];
        yield 'term group' => ['chk_taxonomy_terms_term_group', TermGroup::class];
        yield 'price level' => ['chk_venues_price_level', PriceLevel::class];
        yield 'admin role' => ['chk_admin_users_role', AdminRole::class];
        yield 'audit actor type' => ['chk_admin_audit_log_actor_type', AuditActorType::class];
        yield 'portal venue access status' => ['chk_portal_venue_access_status', PortalVenueAccessStatus::class];
        yield 'portal submission status' => ['chk_portal_submissions_status', SubmissionStatus::class];
        yield 'source record type' => ['chk_source_records_source_type', SourceType::class];
        yield 'source record status' => ['chk_source_records_status', SourceStatus::class];
        yield 'photo candidate origin' => ['chk_photo_candidates_origin', CandidateOrigin::class];
        yield 'photo candidate status' => ['chk_photo_candidates_status', CandidateStatus::class];
        yield 'rights grantor type' => ['chk_media_rights_grants_grantor_type', GrantorType::class];
        yield 'rights revoker type' => ['chk_media_rights_grants_revoked_by_type', GrantorType::class];
        yield 'rights evidence' => ['chk_media_rights_grants_evidence', GrantEvidence::class];
        yield 'complaint kind' => ['chk_moderation_cases_kind', ModerationKind::class];
        yield 'complaint status' => ['chk_moderation_cases_status', ModerationStatus::class];
        yield 'complaint decision' => ['chk_moderation_cases_decision', ModerationDecision::class];
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
