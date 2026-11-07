<?php

declare(strict_types=1);

namespace App\Inquiries\Mailer;

use App\Inquiries\Entity\Inquiry;
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
 * the guest. From comes from MAILER_FROM (config/packages/mailer.yaml), the text part from
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
                'date' => null === $inquiry->eventDate()
                    ? $this->translator->trans('email.date_flexible', [], 'inquiries', $inquiry->locale())
                    : $this->format->date($inquiry->eventDate()),
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
}
