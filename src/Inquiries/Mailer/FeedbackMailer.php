<?php

declare(strict_types=1);

namespace App\Inquiries\Mailer;

use App\Inquiries\Entity\Inquiry;
use App\Inquiries\FeedbackToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The feedback mail to a guest a week after a delivered inquiry (G16): one mail for all the address's inquiries due
 * in a run, one «Rückmeldung geben» button each. Not transactional, so it carries the opt-out link in the footer and
 * as `List-Unsubscribe` with the one-click `List-Unsubscribe-Post` (RFC 8058, Wave G §4a.6); the link names the
 * first inquiry and stops the mails to the address. `INQUIRY_RECIPIENT_OVERRIDE` does not apply: like the guest
 * copy, it goes to the address the guest typed.
 */
final class FeedbackMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly FeedbackToken $tokens,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
    ) {
    }

    /**
     * @param non-empty-list<Inquiry> $inquiries of one address, oldest first
     *
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     * @throws RfcComplianceException      an address `Address` refuses at build time
     */
    public function sendFeedbackRequest(array $inquiries): void
    {
        $latest = $inquiries[\count($inquiries) - 1];
        $locale = $latest->locale();
        $optOutUrl = $this->url('inquiry_feedback_opt_out', $inquiries[0]);

        $message = (new TemplatedEmail())
            ->to($latest->email())
            ->replyTo($this->contactEmail)
            ->subject($this->translator->trans('email.feedback.subject', ['count' => \count($inquiries), 'venue' => $inquiries[0]->venueName()], 'inquiries', $locale))
            ->htmlTemplate('inquiries/email/feedback_request.html.twig')
            ->locale($locale)
            ->context([
                'first_name' => $latest->firstName(),
                'venues' => array_map(fn (Inquiry $inquiry): array => [
                    'name' => $inquiry->venueName(),
                    'url' => $this->url('inquiry_feedback', $inquiry),
                ], $inquiries),
                'opt_out_url' => $optOutUrl,
            ]);
        $message->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$optOutUrl.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($message);
    }

    private function url(string $route, Inquiry $inquiry): string
    {
        return $this->urls->generate($route, ['token' => $this->tokens->forInquiry($inquiry->id())], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
