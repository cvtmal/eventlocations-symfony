<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RecordConsent;
use App\Crm\Entity\Activity;
use App\Crm\Entity\ActivityCompany;
use App\Crm\Entity\ActivityType;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\ReadModel\ConsentQuery;
use App\Crm\Repository\CompanyRepository;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactEmail;
use App\Crm\ValueObject\Outreach;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\Uid\EntityId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes the consent entry under the Location's lock: a `note` with no actor (nobody may edit it, `OwnActivity`),
 * dated noon of the day of the yes, the address and the day in its text and in its payload
 * (`{"consent": {"from", "on", "source"}}`), and the basis of contact "consent" with the other outreach fields kept.
 * The same address and day again write nothing; the handler answers whether it wrote.
 */
final class RecordConsentHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ConsentQuery $consents,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
    ) {
    }

    public function __invoke(RecordConsent $command): bool
    {
        $on = \DateTimeImmutable::createFromFormat('!Y-m-d', $command->consentOn, new \DateTimeZone('Europe/Zurich'));
        if (false === $on || $on->format('Y-m-d') !== $command->consentOn) {
            throw InvalidPropertyValue::unknown('consent_on');
        }
        $email = ContactEmail::normalise($command->email);
        if (null === $email || '' === $email) {
            throw InvalidPropertyValue::required('consent_from');
        }

        $company = $this->companies->lock($command->companyId);
        $outreach = $company->outreach();
        if (ContactBasis::Consent !== $outreach->basis) {
            $company->changeOutreach(new Outreach($outreach->campaignCode, $outreach->cohort, $outreach->channel, ContactBasis::Consent, $outreach->sentOn, $outreach->returnedOn));
        }
        if ($this->consents->has($company->id(), $email, $command->consentOn)) {
            return false;
        }

        $activity = new Activity(
            EntityId::generate(),
            $this->em->getReference(ActivityType::class, ActivityType::NOTE) ?? throw new \LogicException('ActivityType has no subclass, so a reference always resolves.'),
            $on->setTime(12, 0),
            null,
            null,
            $this->translator->trans('timeline.system.consent_recorded', ['address' => $email, 'date' => $on->format('d.m.Y')], 'crm', $this->locales->default()),
            ['consent' => ['from' => $email, 'on' => $command->consentOn, 'source' => $command->source]],
        );
        $this->em->persist($activity);
        $this->em->persist(new ActivityCompany($activity, $company));

        return true;
    }
}
