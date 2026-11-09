<?php

declare(strict_types=1);

namespace App\Inquiries\Mailer;

use App\Inquiries\BriefOptOutToken;
use App\Inquiries\Entity\Inquiry;
use App\Inquiries\ReadModel\StuckInquiry;
use App\Shared\Twig\SwissFormatExtension;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails of an inquiry (IQ-2): to the venue with the guest as Reply-To, and a copy to
 * the guest; and the hourly staff alert about undelivered inquiries (G7, D56) to `site.contact_email`. From comes from MAILER_FROM (config/packages/mailer.yaml), the text part from
 * `EmailHtmlToTextConverter`. The context holds values, never the entity, so a transport stays
 * possible (.claude/rules/cqrs.md); `email` is Twig's name for the message itself.
 *
 * `INQUIRY_RECIPIENT_OVERRIDE` (IQ-4) redirects the venue mail only: the guest copy goes to the
 * address the tester typed. Null and '' both mean no override.
 *
 * A child of a brief to several venues (F13) tells the venue how many venues got the brief, never which, and carries
 * the venue's one-click opt-out of briefs; the guest gets one confirmation for the whole brief instead of a copy per
 * venue.
 */
final class InquiryMailer
{
    public const ORIGINAL_RECIPIENT_HEADER = 'X-Inquiry-Original-Recipient';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly SwissFormatExtension $format,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
        #[Autowire('%env(default::INQUIRY_RECIPIENT_OVERRIDE)%')]
        private readonly ?string $recipientOverride,
        private readonly UrlGeneratorInterface $urls,
        private readonly BriefOptOutToken $briefOptOutTokens,
    ) {
    }

    /**
     * @return string the real envelope recipient, for `inquiries.delivered_to`
     *
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     * @throws RfcComplianceException      an address `Address` refuses at build time
     */
    public function sendToVenue(Inquiry $inquiry): string
    {
        $override = (string) $this->recipientOverride;
        $recipient = '' === $override ? $inquiry->recipientEmail() : $override;
        $guestName = $inquiry->firstName().' '.$inquiry->lastName();

        $brief = $this->briefContext($inquiry);
        $message = (new TemplatedEmail())
            ->to($recipient)
            ->replyTo(new Address($inquiry->email(), $guestName))
            ->subject($this->translator->trans(null === $inquiry->brief() ? 'email.venue.subject' : 'email.brief.venue_subject', [
                'venue' => $inquiry->venueName(),
                'name' => $guestName,
                'event_type' => self::eventType($inquiry)->trans($this->translator, $inquiry->locale()),
                'date' => $this->date($inquiry),
                'guests' => $this->format->number($inquiry->guestCount()),
            ], 'inquiries', $inquiry->locale()))
            ->htmlTemplate('inquiries/email/venue.html.twig')
            ->locale($inquiry->locale())
            ->context($this->context($inquiry) + $brief);

        // The brief opt-out takes RFC 8058's one-click POST, as the feedback and owner-report mails do (F16).
        if (null !== $brief['brief_opt_out_url']) {
            $message->getHeaders()
                ->addTextHeader('List-Unsubscribe', '<'.$brief['brief_opt_out_url'].'>')
                ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        if ('' !== $override) {
            $message->getHeaders()->addTextHeader(self::ORIGINAL_RECIPIENT_HEADER, $inquiry->recipientEmail());
        }

        $this->mailer->send($message);

        return $recipient;
    }

    /**
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     * @throws RfcComplianceException      an address `Address` refuses at build time
     */
    public function sendToGuest(Inquiry $inquiry): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to($inquiry->email())
            ->replyTo($this->contactEmail)
            ->subject($this->translator->trans('email.guest.subject', ['venue' => $inquiry->venueName()], 'inquiries', $inquiry->locale()))
            ->htmlTemplate('inquiries/email/guest.html.twig')
            ->locale($inquiry->locale())
            ->context($this->context($inquiry)));
    }

    /**
     * The one confirmation of a brief (F13): the guest's details once, the venues reached, and those staff still
     * deliver.
     *
     * @param non-empty-list<Inquiry> $reached     children whose venue mail went out, in the guest's order
     * @param list<Inquiry>           $undelivered the other children
     *
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     * @throws RfcComplianceException      an address `Address` refuses at build time
     */
    public function sendBriefConfirmation(array $reached, array $undelivered): void
    {
        $first = $reached[0];
        $this->mailer->send((new TemplatedEmail())
            ->to($first->email())
            ->replyTo($this->contactEmail)
            ->subject($this->translator->trans('email.brief.guest_subject', ['count' => \count($reached)], 'inquiries', $first->locale()))
            ->htmlTemplate('inquiries/email/brief_guest.html.twig')
            ->locale($first->locale())
            ->context($this->context($first) + [
                'reached' => array_map(static fn (Inquiry $child): array => ['name' => $child->venueName(), 'slug' => $child->venueSlug()], $reached),
                'undelivered' => array_map(static fn (Inquiry $child): string => $child->venueName(), $undelivered),
            ]));
    }

    /**
     * Tells staff which inquiries did not reach their venue. Names the inquiry, its venue and its state, never the
     * guest: the link opens the CRM detail, where staff resend it.
     *
     * @param non-empty-list<StuckInquiry> $rows
     *
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     */
    public function sendStuckAlert(array $rows): void
    {
        $zone = new \DateTimeZone('Europe/Zurich');

        $this->mailer->send((new TemplatedEmail())
            ->to($this->contactEmail)
            ->subject($this->translator->trans('email.stuck.subject', ['count' => \count($rows)], 'inquiries', 'de'))
            ->htmlTemplate('inquiries/email/stuck_alert.html.twig')
            ->locale('de')
            ->context([
                'inquiries' => array_map(static fn (StuckInquiry $row): array => [
                    'id' => $row->id,
                    'venue_name' => $row->venueName,
                    'venue_number' => $row->venueNumber,
                    'status' => $row->venueMailStatus->value,
                    'created_at' => $row->createdAt->setTimezone($zone)->format('d.m.Y H:i'),
                ], $rows),
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Inquiry $inquiry): array
    {
        return [
            'venue_name' => $inquiry->venueName(),
            'venue_slug' => $inquiry->venueSlug(),
            'event_type' => self::eventType($inquiry),
            'company' => $inquiry->company(),
            'guests' => $inquiry->guestCount(),
            'event_date' => $inquiry->eventDate(),
            'date_flexible' => $inquiry->isDateFlexible(),
            'start_time' => $inquiry->startTime()?->format('H:i'),
            'catering_wish' => null === $inquiry->cateringWish() ? null : new Term(TermGroup::Catering, $inquiry->cateringWish()),
            'budget_chf' => $inquiry->budgetChf(),
            'message' => $inquiry->message(),
            'first_name' => $inquiry->firstName(),
            'last_name' => $inquiry->lastName(),
            'guest_email' => $inquiry->email(),
            'phone' => $inquiry->phone(),
        ];
    }

    /**
     * @return array{brief_venue_count: int|null, brief_opt_out_url: string|null}
     */
    private function briefContext(Inquiry $inquiry): array
    {
        $brief = $inquiry->brief();

        return [
            'brief_venue_count' => $brief?->venueCount(),
            'brief_opt_out_url' => null === $brief ? null : $this->urls->generate(
                'inquiry_brief_opt_out',
                ['token' => $this->briefOptOutTokens->forVenue($inquiry->venueNumber())],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
        ];
    }

    private static function eventType(Inquiry $inquiry): Term
    {
        return new Term(TermGroup::SuitableFor, $inquiry->eventType());
    }

    /**
     * The subject's date, as the facts row says it: "flexibel", the date, or "{date} (flexibel)".
     */
    private function date(Inquiry $inquiry): string
    {
        $date = $inquiry->eventDate();
        if (null === $date) {
            return $this->translator->trans('email.date_flexible', [], 'inquiries', $inquiry->locale());
        }

        return $inquiry->isDateFlexible()
            ? $this->translator->trans('email.date_and_flexible', ['date' => $this->format->date($date)], 'inquiries', $inquiry->locale())
            : $this->format->date($date);
    }
}
