<?php

declare(strict_types=1);

namespace App\Story;

use App\Admin\Repository\AdminUserRepository;
use App\Crm\Command\ChangeInquiryStatus;
use App\Crm\Command\IngestInquiry;
use App\Crm\CommandHandler\LinkLocationManagerHandler;
use App\Crm\CompanyStageSync;
use App\Crm\Entity\ActivityType;
use App\Crm\Entity\Company;
use App\Crm\Entity\Contact;
use App\Crm\Repository\CompanyRepository;
use App\Crm\Repository\SuppressionRepository;
use App\Crm\ValueObject\CompanyOrigin;
use App\Crm\ValueObject\CompanyStage;
use App\Crm\ValueObject\ContactBasis;
use App\Crm\ValueObject\ContactChannel;
use App\Crm\ValueObject\InquiryStatus;
use App\Crm\ValueObject\Outreach;
use App\Crm\ValueObject\OutreachCohort;
use App\Crm\ValueObject\SuppressionReason;
use App\Factory\ActivityFactory;
use App\Factory\CompanyFactory;
use App\Factory\ContactFactory;
use App\Factory\DealFactory;
use App\Factory\EmailTemplateFactory;
use App\Factory\InquiryFactory;
use App\Factory\TaskFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ValueObject\MailStatus;
use App\Venues\Entity\Venue;
use App\Venues\Repository\VenueRepository;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Story;

use function Zenstruck\Foundry\faker;

/**
 * Fictional CRM data for the dev back office (#27): 12 companies, 8 of them made from the first
 * demo venues (so the bootstrap button still has venues to work on), their contact rows (the first
 * venue company's first one a Location-Manager, #55), timeline entries and tasks of the dev admin, due today, overdue and later, 14 deals over every stage of
 * the pipeline, six inquiries on the linked venues for the inbox (#29), and two mail templates
 * (#30). Seeded, so every `make seed` on an empty CRM writes the same data; it skips when a
 * company exists, because `make seed` appends.
 */
final class CrmStory extends Story
{
    private const LINKED_VENUES = 8;
    /** G11's demo postal campaign; the Akquise page prints its list. */
    public const string CAMPAIGN = 'brief-2026-11';
    private const MANUAL_COMPANIES = ['Eventwerk GmbH', 'Seeland Events AG', 'Alpenblick Hotels', 'Gastro Kollektiv'];
    /** A stage, and for a won deal its contract's end in days from today: one ended, one renewal due within 60 days, one running. */
    private const DEALS = [
        ['new', null], ['contacted', null], ['offer', null], ['negotiation', null], ['won', -30], ['lost', null], ['new', null],
        ['offer', null], ['won', 45], ['negotiation', null], ['contacted', null], ['won', 240], ['lost', null], ['offer', null],
    ];
    /** The venue mail's outcome and the age in days, newest first. The last two end up `seen` and `done`, the others stay `new`. */
    private const INQUIRIES = [
        [MailStatus::Pending, 1], [MailStatus::Failed, 2], [MailStatus::Sent, 3], [MailStatus::Failed, 6], [MailStatus::Sent, 10], [MailStatus::Sent, 17],
    ];
    /** Name, subject and Markdown, each template using all three merge fields. */
    private const EMAIL_TEMPLATES = [
        [
            'Verlängerung Tier',
            'Verlängerung des Eintrags von {venue.name}',
            "Hallo {contact.first_name}\n\n"
            ."Der Eintrag von {venue.name} läuft bald aus. Damit {location.name} ohne Unterbruch sichtbar bleibt, verlängern wir ihn gerne:\n\n"
            ."- gleiche Stufe, gleiche Leistungen\n"
            ."- neue Laufzeit von zwölf Monaten\n"
            ."- Rechnung nach deiner Zusage\n\n"
            ."Die Stufen im Überblick: [deine-eventlocation.ch](https://deine-eventlocation.ch)\n\n"
            .'Freundliche Grüsse',
        ],
        [
            'Fotos anfordern',
            'Neue Fotos für {venue.name}',
            "Hallo {contact.first_name}\n\n"
            ."Für den Eintrag von {venue.name} bei {location.name} hätten wir gerne neue Bilder. Könntest du uns bis Ende Monat Folgendes senden?\n\n"
            ."- fünf bis zehn Fotos im Querformat\n"
            ."- ein Foto des Festsaals, gedeckt für ein Galadinner\n"
            ."- ein Foto der Aussenansicht\n\n"
            .'Herzlichen Dank und freundliche Grüsse',
        ],
    ];

    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly VenueRepository $venues,
        private readonly AdminUserRepository $users,
        private readonly CompanyStageSync $stageSync,
        private readonly MessageBusInterface $commandBus,
        private readonly SuppressionRepository $suppressions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function build(): void
    {
        if ($this->companies->count([]) > 0) {
            return;
        }
        $admin = $this->users->findOneByEmail(AdminStory::EMAIL)
            ?? throw new \LogicException('CrmStory needs the dev admin: load AdminStory first (AppStory does).');

        $faker = faker();
        $faker->seed(20261031);
        $stages = [CompanyStage::Customer, CompanyStage::Prospect, CompanyStage::Prospect, CompanyStage::FormerCustomer];
        $types = [ActivityType::NOTE, ActivityType::CALL, ActivityType::MEETING, ActivityType::EMAIL_LOGGED];
        $today = new \DateTimeImmutable('today');

        $companies = [];
        $venues = $this->venues->findBy([], ['number' => 'ASC'], self::LINKED_VENUES);
        foreach ($venues as $index => $venue) {
            $companies[] = CompanyFactory::new()
                ->inStage($stages[$index % \count($stages)])
                ->withVenue($venue)
                ->create([
                    'name' => $venue->name(),
                    'origin' => CompanyOrigin::Venue,
                    'owner' => 0 === $index % 3 ? null : $admin,
                ]);
        }
        foreach (self::MANUAL_COMPANIES as $index => $name) {
            $companies[] = CompanyFactory::new()->withDetails()->inStage($stages[($index + 1) % \count($stages)])->create(['name' => $name, 'owner' => $admin]);
        }

        foreach ($companies as $index => $company) {
            foreach (range(1, 1 + $index % 2) as $number) {
                $contact = ContactFactory::new()->withDetails();
                if (0 === $index && 1 === $number) {
                    $contact = $contact->afterInstantiate(static fn (Contact $manager) => $manager->changePosition(LinkLocationManagerHandler::MANAGER_POSITION));
                }
                $contact->create([
                    'company' => $company,
                    'email' => 0 === $index % 4 ? null : $faker->unique()->safeEmail(),
                ]);
            }

            foreach (range(0, $index % 4) as $entry) {
                ActivityFactory::new()->ofType($types[($index + $entry) % \count($types)])->onCompany($company)->create(['actor' => $admin]);
            }

            if (0 === $index % 2) {
                TaskFactory::createOne([
                    'assignee' => $admin,
                    'createdBy' => $admin,
                    'dueOn' => $today->modify(\sprintf('%+d days', ($index % 5) - 2)),
                    'company' => $company,
                ]);
            }
        }

        TaskFactory::new()->done($today->modify('-1 day')->setTime(16, 0))->create(['assignee' => $admin, 'createdBy' => $admin, 'dueOn' => $today->modify('-2 days')]);

        $this->deals($companies, $venues, $today);
        $this->inquiries($venues, $today);
        $this->outreach($companies, $today);
        foreach (self::EMAIL_TEMPLATES as [$name, $subject, $body]) {
            EmailTemplateFactory::createOne(['name' => $name, 'subject' => $subject, 'bodyMarkdown' => $body]);
        }
        $this->stageSync->syncAll();
    }

    /**
     * G11: the venue Locations form the pilot's four cohorts (A and B by letter in the campaign `brief-2026-11`, sent 11
     * weeks ago so FunnelStory's steps count, one letter came back; C by a one-to-one mail, D by a referral), one manual
     * Location took down an objection and one address opted out. The demo addresses are fixtures, never real contacts.
     *
     * @param list<Company> $companies
     */
    private function outreach(array $companies, \DateTimeImmutable $today): void
    {
        $sent = $today->modify('-77 days');
        $plan = [
            [OutreachCohort::A, ContactChannel::Postal, ContactBasis::None, true, null],
            [OutreachCohort::A, ContactChannel::Postal, ContactBasis::None, true, $today->modify('-70 days')],
            [OutreachCohort::B, ContactChannel::Postal, ContactBasis::None, true, null],
            [OutreachCohort::B, ContactChannel::Postal, ContactBasis::None, true, null],
            [OutreachCohort::C, ContactChannel::Email, ContactBasis::OneToOne, false, null],
            [OutreachCohort::C, ContactChannel::Email, ContactBasis::Customer, false, null],
            [OutreachCohort::D, ContactChannel::Referral, ContactBasis::OneToOne, false, null],
        ];
        foreach ($plan as $index => [$cohort, $channel, $basis, $postal, $returned]) {
            $companies[$index]->changeOutreach(new Outreach($postal ? self::CAMPAIGN : null, $cohort, $channel, $basis, $sent, $returned));
        }
        $this->em->flush();

        $now = new \DateTimeImmutable();
        $this->suppressions->addCompany($companies[\count($companies) - 1]->id(), SuppressionReason::Objection, $now);
        $this->suppressions->addEmail('kein-kontakt@example.ch', SuppressionReason::OptOut, $now);
    }

    /**
     * @param list<Company> $companies
     * @param list<Venue>   $venues    the first companies' venues, in the same order
     */
    private function deals(array $companies, array $venues, \DateTimeImmutable $today): void
    {
        $faker = faker();
        $admin = $this->users->findOneByEmail(AdminStory::EMAIL);

        foreach (self::DEALS as $index => [$stage, $contractEnd]) {
            $company = $index % \count($companies);
            // The factory resolves the code to the seeded row (F12).
            $tier = $faker->randomElement([KnownTiers::FREE, KnownTiers::PRO]);
            $attributes = [
                'name' => ucfirst($tier).' '.$today->format('Y'),
                'company' => $companies[$company],
                'venue' => $venues[$company] ?? null,
                'tier' => $tier,
                'amountRappen' => $faker->numberBetween(1, 40) * 50000,
                'owner' => 0 === $index % 3 ? null : $admin,
                'expectedCloseOn' => $today->modify(\sprintf('%+d days', 7 * ($index + 1))),
            ];
            if (null !== $contractEnd) {
                $end = $today->modify(\sprintf('%+d days', $contractEnd));
                $attributes['contractEndOn'] = $end;
                $attributes['contractStartOn'] = $end->modify('-1 year +1 day');
            }

            DealFactory::new()->inStage($stage, $today->modify(\sprintf('-%d days', $index + 1))->setTime(10, 0))->create($attributes);
        }
    }

    /**
     * Each goes through `IngestInquiry`, as a real one does, so the inbox and the timeline stay in step; a couple is no
     * contact (#55). A demo venue without an inquiry address can take no inquiry.
     *
     * @param list<Venue> $venues
     */
    private function inquiries(array $venues, \DateTimeImmutable $today): void
    {
        $addressed = array_values(array_filter($venues, static fn (Venue $venue): bool => null !== $venue->inquiryEmail()));

        $ids = [];
        foreach (self::INQUIRIES as $index => [$mail, $age]) {
            $at = $today->modify(\sprintf('-%d days', $age))->setTime(9 + $index, 0);
            $inquiry = InquiryFactory::new()
                ->afterInstantiate(static function (Inquiry $inquiry) use ($mail, $at): void {
                    if (MailStatus::Sent === $mail) {
                        $inquiry->recordVenueMailSent($inquiry->recipientEmail(), $at);
                        $inquiry->recordGuestMail(MailStatus::Sent, $at);
                    }
                    if (MailStatus::Failed === $mail) {
                        $inquiry->recordVenueMailFailed($at);
                        $inquiry->recordGuestMail(MailStatus::Skipped, $at);
                    }
                })
                ->create(['venue' => $addressed[$index % \count($addressed)], 'createdAt' => $at]);

            $this->commandBus->dispatch(new IngestInquiry($inquiry->id()));
            $ids[] = $inquiry->id();
        }

        $this->commandBus->dispatch(new ChangeInquiryStatus($ids[4], InquiryStatus::Seen));
        $this->commandBus->dispatch(new ChangeInquiryStatus($ids[5], InquiryStatus::Done));
    }
}
