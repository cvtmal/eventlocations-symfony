<?php

declare(strict_types=1);

namespace App\Tests\Unit\Venues;

use App\Shared\Messenger\AuditableCommand;
use App\Venues\Command\AnswerVenueFaq;
use App\Venues\Command\ChangeVenueBasics;
use App\Venues\Command\ChangeVenueContact;
use App\Venues\Command\ChangeVenueTerms;
use App\Venues\Command\ChangeVenueTexts;
use App\Venues\Command\ChangeVenueWeddingFacts;
use App\Venues\Command\DeleteVenue;
use App\Venues\Command\PublishVenue;
use App\Venues\Command\RateVenue;
use App\Venues\Command\RecordVenueCreated;
use App\Venues\Command\RelocateVenue;
use App\Venues\Command\UnpublishVenue;
use App\Venues\ValueObject\OwnCatering;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use App\Venues\ValueObject\RatingHighlight;
use App\Venues\ValueObject\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every auditable venue command of #24 (D9, D11, S12): the subject is the venue's number,
 * and the summary stored in the append-only audit log holds field names and short
 * vocabulary values only, never free text, contact data or a coordinate.
 */
final class VenueCommandAuditTest extends TestCase
{
    private const int NUMBER = 4711;

    /** Values that must never reach a summary. */
    private const array SECRETS = [
        'GEHEIMTEXT',
        'geheim@beispiel.ch',
        '+41449998877',
        'https://geheim.example',
        'Geheime Person',
        '47.123456',
        '8.654321',
        'Seestrasse 99',
        'Geheimregion',
    ];

    /**
     * @return iterable<string, array{AuditableCommand}>
     */
    public static function commands(): iterable
    {
        $text = 'GEHEIMTEXT';
        $changed = ['name', 'summary'];

        yield 'basics' => [new ChangeVenueBasics(self::NUMBER, '1', $changed, 'Schloss Muster', 'schloss-muster', Tier::Premium, 10, 100)];
        yield 'texts' => [new ChangeVenueTexts(self::NUMBER, '1', $changed, ['de' => ['summary' => $text, 'description' => $text, 'metaDescription' => $text, 'parkingText' => $text]])];
        yield 'relocate' => [new RelocateVenue(self::NUMBER, '1', $changed, '8706 Meilen', 'zh', 'Seestrasse 99', 47.123456, 8.654321, '1.5', $text, '2.5', $text, '30.0', $text, ['de' => 'Geheimregion'], true, true)];
        yield 'terms' => [new ChangeVenueTerms(self::NUMBER, '1', $changed, ['building' => ['castle']], ['building' => 'castle'])];
        yield 'wedding facts' => [new ChangeVenueWeddingFacts(self::NUMBER, '1', $changed, PriceLevel::Medium, 80, 120, PartyAllowed::Yes, true, '23:00', ['de' => ['ambience' => $text, 'capacityNote' => $text, 'partyNote' => $text, 'openingHours' => $text]], ['ceremony' => ['chapel']])];
        yield 'contact' => [new ChangeVenueContact(self::NUMBER, '1', $changed, '+41449998877', 'geheim@beispiel.ch', 'https://geheim.example', 'Geheime Person', 'https://geheim.example/insta')];
        yield 'faq' => [new AnswerVenueFaq(self::NUMBER, '1', $changed, OwnCatering::Allowed, true)];
        yield 'rate' => [new RateVenue(self::NUMBER, '1', $changed, '8.4', 12, [RatingHighlight::Food])];
        yield 'created' => [new RecordVenueCreated(self::NUMBER, 'Schloss Muster')];
        yield 'publish' => [new PublishVenue(self::NUMBER)];
        yield 'unpublish' => [new UnpublishVenue(self::NUMBER)];
        yield 'delete' => [new DeleteVenue(self::NUMBER, 'Schloss Muster', 'schloss-muster')];
    }

    #[DataProvider('commands')]
    public function test_the_subject_is_the_venue_number(AuditableCommand $command): void
    {
        self::assertSame('venue', $command->auditSubject()->type);
        self::assertSame((string) self::NUMBER, $command->auditSubject()->id);
    }

    #[DataProvider('commands')]
    public function test_the_summary_holds_no_free_text_contact_data_or_coordinate(AuditableCommand $command): void
    {
        $summary = json_encode($command->auditSummary(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $summary);
        }
    }

    public function test_a_form_command_lists_its_changed_fields(): void
    {
        self::assertSame(['changed' => ['phone']], new ChangeVenueContact(self::NUMBER, '1', ['phone'], '+41449998877', null, null, null, null)->auditSummary());
    }
}
