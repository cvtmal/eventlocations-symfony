<?php

declare(strict_types=1);

namespace App\Story;

use App\Crm\Command\CreateCompanyForVenue;
use App\Crm\Command\ImportLocation;
use App\Crm\Command\RecordConsent;
use App\Crm\ReadModel\VenueCompanyQuery;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Shared\Uid\EntityId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Story;

/**
 * F14: what the onboarding commands leave in the back office, through the same commands. One Location as
 * `app:crm:import-contacts` makes it from the first row of the contact template (`tests/Fixtures/Onboarding/contacts.csv`),
 * and PreviewStory's draft «Remise am Bach» as a consent entry (D1(c), D29): its Location with the yes on the timeline,
 * as `app:crm:record-consents` writes it. The draft's facts are unchecked, so its CMS publish names «Fakten nicht
 * geprüft»; a claim from `@remise-am-bach.example` (G6) checks them (D45). Every value is fictional. Loaded after
 * CrmStory and PreviewStory; skips when its Location exists, so a second `make seed` writes nothing.
 */
final class OnboardingStory extends Story
{
    public const string LOCATION = 'Seehof Muster AG';
    public const string CONSENT_FROM = 'hallo@remise-am-bach.example';

    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $commandBus,
        private readonly VenueCompanyQuery $companies,
    ) {
    }

    public function build(): void
    {
        if (false !== $this->connection->fetchOne('SELECT 1 FROM crm_companies WHERE name = ?', [self::LOCATION])) {
            return;
        }

        $this->commandBus->dispatch(new ImportLocation(
            EntityId::generate(),
            self::LOCATION,
            'Seestrasse 10, 8712 Stäfa',
            'info@seehof-muster.example',
            '+41445550101',
            'https://seehof-muster.example',
            EntityId::generate(),
            'Lena',
            'Beispiel',
            'Geschäftsführerin',
            'lena@seehof-muster.example',
            '+41795550102',
            new Outreach(CrmStory::CAMPAIGN, OutreachCohort::A, ContactChannel::Postal, ContactBasis::OneToOne),
            null,
        ));

        $venueId = $this->connection->fetchOne("SELECT id FROM venues WHERE name = 'Remise am Bach' AND content_key IS NULL ORDER BY number LIMIT 1");
        if (!\is_string($venueId)) {
            return;
        }
        $companyId = $this->companies->companyIdOf($venueId);
        if (null === $companyId) {
            $companyId = EntityId::generate();
            $this->commandBus->dispatch(new CreateCompanyForVenue($venueId, $companyId));
        }
        $this->commandBus->dispatch(new RecordConsent($companyId, self::CONSENT_FROM, (new \DateTimeImmutable('today'))->modify('-20 days')->format('Y-m-d')));
    }
}
