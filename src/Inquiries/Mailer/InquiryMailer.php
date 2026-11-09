<?php

declare(strict_types=1);

namespace App\Inquiries\Mailer;

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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails of an inquiry (IQ-2): to the venue with the guest as Reply-To, and a copy to
 * the guest; and the hourly staff alert about undelivered inquiries (G7, D56) to `site.contact_email`. From comes from MAILER_FROM (config/packages/mailer.yaml), the text part from
 * `EmailHtmlToTextConverter`. The context holds values, never the entity, so a transport stays
 * possible (.claude/rules/cqrs.md); `email` is Twig's name for the message itself.
 *
 * `INQUIRY_RECIPIENT_OVERRIDE` (IQ-4) redirects the venue mail only: the guest copy goes to the
 * address the tester typed. Null and '' both mean no override.
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

        $message = (new TemplatedEmail())
            ->to($recipient)
            ->replyTo(new Address($inquiry->email(), $guestName))
            ->subject($this->translator->trans('email.venue.subject', [
                'venue' => $inquiry->venueName(),
                'name' => $guestName,
                'event_type' => self::eventType($inquiry)->trans($this->translator, $inquiry->locale()),
                'date' => $this->date($inquiry),
                'guests' => $this->format->number($inquiry->guestCount()),
            ], 'inquiries', $inquiry->locale()))
            ->htmlTemplate('inquiries/email/venue.html.twig')
            ->locale($inquiry->locale())
            ->context($this->context($inquiry));

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
