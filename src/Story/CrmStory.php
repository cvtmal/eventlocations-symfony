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
use App\Factory\InquiryBriefFactory;
use App\Factory\InquiryFactory;
use App\Factory\InquiryFeedbackFactory;
use App\Factory\TaskFactory;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ValueObject\FeedbackOutcome;
use App\Inquiries\ValueObject\InquiryOverride;
use App\Inquiries\ValueObject\MailStatus;
use App\Inquiries\ValueObject\QualificationReason;
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
    /** The venue mail's outcome and the age in days, newest first. The fourth (marked test) and the sixth end up `done`, the fifth `seen`, the others stay `new`. */
    private const INQUIRIES = [
        [MailStatus::Pending, 1], [MailStatus::Failed, 2], [MailStatus::Sent, 3], [MailStatus::Failed, 6], [MailStatus::Sent, 10], [MailStatus::Sent, 17],
        [MailStatus::Sent, 9],
    ];
    /**
     * G7 (D56): the qualification each delivered or failed one got, and the staff mark, so the inbox shows every pill.
     * The pending one has none yet; the one marked test is filed `done` as marking a `new` one does.
     */
    private const INQUIRY_QUALITY = [
        1 => [QualificationReason::NotDelivered, null],
        2 => [null, null],
        3 => [QualificationReason::NotDelivered, InquiryOverride::Test],
        4 => [QualificationReason::EventTypeNotOffered, null],
        5 => [null, InquiryOverride::Spam],
        6 => [null, null],
    ];
    /** G16: the index of the qualified inquiry whose guest answered the feedback mail a day after it went out. */
    private const INQUIRY_WITH_FEEDBACK = 6;

    /**
     * The event fields of those six inquiries (F9): event type, company, days until the date (null: flexible only),
     * flexible, start time, catering wish, budget in CHF.
     */
    private const INQUIRY_EVENTS = [
        ['corporate_event', 'Muster Treuhand AG', 60, false, '18:00', 'in_house', 15000],
        ['seminar', 'Alpina Software GmbH', 30, true, '09:00', 'in_house', null],
        ['birthday', null, 90, false, '19:00', 'external_allowed', 4000],
        ['christmas_party', 'Bäckerei Zentral', 45, false, '18:30', null, 8000],
        ['general_assembly', 'Wohnbaugenossenschaft Linde', null, true, null, null, null],
        ['wedding', null, 200, true, '14:00', 'in_house', 25000],
        ['apero', 'Kanzlei Bergmann', 21, false, '17:30', 'external_allowed', 3000],
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
     * Each goes through `IngestInquiry`, as a real one does, so the inbox and the timeline stay in step; an organizer is no
     * contact (S13). A demo venue without an inquiry address can take no inquiry.
     *
     * @param list<Venue> $venues
     */
    private function inquiries(array $venues, \DateTimeImmutable $today): void
    {
        $addressed = array_values(array_filter($venues, static fn (Venue $venue): bool => null !== $venue->inquiryEmail()));

        $ids = [];
        foreach (self::INQUIRIES as $index => [$mail, $age]) {
            $at = $today->modify(\sprintf('-%d days', $age))->setTime(9 + $index, 0);
            [$eventType, $company, $inDays, $flexible, $startTime, $catering, $budget] = self::INQUIRY_EVENTS[$index];
            $inquiry = InquiryFactory::new()
                ->afterInstantiate(static function (Inquiry $inquiry) use ($mail, $at, $index): void {
                    if (MailStatus::Sent === $mail) {
                        $inquiry->recordVenueMailSent($inquiry->recipientEmail(), $at);
                        $inquiry->recordGuestMail(MailStatus::Sent, $at);
                    }
                    if (MailStatus::Failed === $mail) {
                        $inquiry->recordVenueMailFailed($at);
                        $inquiry->recordGuestMail(MailStatus::Skipped, $at);
                    }
                    if (isset(self::INQUIRY_QUALITY[$index])) {
                        [$reason, $override] = self::INQUIRY_QUALITY[$index];
                        $inquiry->recordQualification($reason, $at);
                        $inquiry->mark($override, $at);
                    }
                })
                ->create([
                    'venue' => $addressed[$index % \count($addressed)],
                    'createdAt' => $at,
                    'eventType' => $eventType,
                    'company' => $company,
                    'eventDate' => null === $inDays ? null : $today->modify(\sprintf('+%d days', $inDays)),
                    'dateFlexible' => $flexible,
                    'startTime' => null === $startTime ? null : new \DateTimeImmutable('1970-01-01 '.$startTime),
                    'cateringWish' => $catering,
                    'budgetChf' => $budget,
                ]);

            $this->commandBus->dispatch(new IngestInquiry($inquiry->id()));
            $ids[] = $inquiry->id();

            if (self::INQUIRY_WITH_FEEDBACK === $index) {
                InquiryFeedbackFactory::new()
                    ->answered(true, FeedbackOutcome::Yes, $at->modify('+8 days'))
                    ->create(['inquiry' => $inquiry, 'at' => $at->modify('+7 days')]);
            }
        }

        $this->brief($addressed, $today);

        $this->commandBus->dispatch(new ChangeInquiryStatus($ids[3], InquiryStatus::Done));
        $this->commandBus->dispatch(new ChangeInquiryStatus($ids[4], InquiryStatus::Seen));
        $this->commandBus->dispatch(new ChangeInquiryStatus($ids[5], InquiryStatus::Done));
    }

    /**
     * F13: a brief to three venues two days ago, delivered to all three; the guest got one confirmation, so the
     * children carry no guest copy. Each child goes through `IngestInquiry` like any inquiry.
     *
     * @param list<Venue> $addressed venues with an inquiry address
     */
    private function brief(array $addressed, \DateTimeImmutable $today): void
    {
        if (\count($addressed) < 3) {
            return;
        }
        $at = $today->modify('-2 days')->setTime(16, 30);
        $brief = InquiryBriefFactory::createOne(['venueCount' => 3, 'createdAt' => $at]);
        $brief->recordGuestMail(MailStatus::Sent, $at);
        foreach (\array_slice($addressed, 0, 3) as $venue) {
            $inquiry = InquiryFactory::new()
                ->afterInstantiate(static function (Inquiry $inquiry) use ($at): void {
                    $inquiry->recordVenueMailSent($inquiry->recipientEmail(), $at);
                    $inquiry->recordGuestMail(MailStatus::Skipped, $at);
                    $inquiry->recordQualification(null, $at);
                })
                ->create([
                    'venue' => $venue,
                    'brief' => $brief,
                    'createdAt' => $at,
                    'eventType' => 'christmas_party',
                    'company' => 'Muster Treuhand AG',
                    'guestCount' => 45,
                    'eventDate' => null,
                    'dateFlexible' => true,
                    'firstName' => 'Sandra',
                    'lastName' => 'Keller',
                    'email' => 'sandra.keller@example.ch',
                ]);
            $this->commandBus->dispatch(new IngestInquiry($inquiry->id()));
        }
    }
}
