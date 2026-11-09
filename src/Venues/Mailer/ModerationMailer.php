<?php

declare(strict_types=1);

namespace App\Venues\Mailer;

use App\Shared\Mailer\ReplyTo;
use App\Venues\ReadModel\ModerationCaseView;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two mails of a complaint (G2), sent by the controller after the case is committed: a receipt to the
 * complainant and a notice to staff at `site.contact_email`. Both on the `emails` layout; the text part comes from
 * `EmailHtmlToTextConverter`. The receipt is transactional (it answers the visitor's own submit), so it carries no
 * opt-out link.
 *
 * The receipt goes to an address nobody has proved, so it quotes nothing the visitor typed (no name, no message):
 * otherwise anybody could send any text from our domain to a stranger. It names the kind, the venue as the site
 * knows it, the case's reference and whether photos were hidden. The staff notice carries everything, with the
 * complainant as Reply-To.
 *
 * A dead mail server loses no complaint: the case is stored, a failure is logged (case id and where the cause was
 * raised, never its message, which can quote an address) and answered with false.
 */
final class ModerationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
    ) {
    }

    /**
     * Both mails go right after the report; a case whose complainant D36 erased has no one to write to or to name.
     */
    public function sendReceipt(ModerationCaseView $case): bool
    {
        if (null === $case->complainantEmail) {
            return false;
        }

        return $this->send($case, (new TemplatedEmail())
            ->to($case->complainantEmail)
            ->replyTo($this->contactEmail)
            ->subject($this->translator->trans('report.mail.receipt.subject', [], 'venues'))
            ->htmlTemplate('venues/email/report_receipt.html.twig')
            ->context(self::context($case)));
    }

    /**
     * @param bool $holdLimitReached the complaint named photos, but the site-wide daily budget of holds was spent
     *                               (`content_report_holds`), so nothing was hidden
     */
    public function sendNotice(ModerationCaseView $case, bool $holdLimitReached = false): bool
    {
        if (null === $case->complainantEmail) {
            return false;
        }

        $message = (new TemplatedEmail())->to($this->contactEmail);
        $complainant = ReplyTo::person($case->complainantEmail, $case->complainantName);
        if (null !== $complainant) {
            $message->replyTo($complainant);
        }

        return $this->send($case, $message
            ->subject($this->translator->trans('report.mail.notice.subject', [
                'kind' => $case->kind->value,
                'venue' => $case->venueName ?? $case->venueReference ?? '',
            ], 'venues'))
            ->htmlTemplate('venues/email/report_notice.html.twig')
            ->context(self::context($case) + ['hold_limit_reached' => $holdLimitReached]));
    }

    private function send(ModerationCaseView $case, TemplatedEmail $message): bool
    {
        try {
            $this->mailer->send($message);

            return true;
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('The mail {template} of moderation case {case} failed ({exception_class} at {file}:{line}).', [
                'template' => $message->getHtmlTemplate(),
                'case' => $case->id,
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Values only, never the view: `email` is Twig's name for the message itself, so the address is `complainant_email`.
     *
     * @return array<string, mixed>
     */
    private static function context(ModerationCaseView $case): array
    {
        return [
            'case_id' => $case->id,
            'reference' => strtoupper(substr($case->id, -8)),
            'kind' => $case->kind,
            'venue_name' => $case->venueName,
            'venue_number' => $case->venueNumber,
            'venue_reference' => $case->venueReference,
            'held_photos' => $case->heldPhotos(),
            'complainant_name' => $case->complainantName,
            'complainant_email' => $case->complainantEmail,
            'message' => $case->message,
            'source_url' => $case->sourceUrl,
        ];
    }
}
