<?php

declare(strict_types=1);

namespace App\Crm\Mailer;

use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\Translation\EnabledLocale;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * A staff member's one-to-one mail (CRM-10): MAILER_FROM's address with the staff member's name,
 * replies to the shared mailbox. The Markdown goes out as the text part, as written, so the
 * site's HTML-to-text converter is skipped. An explicit From stays, the `headers` entry of
 * config/packages/mailer.yaml only fills an empty one.
 *
 * `INQUIRY_RECIPIENT_OVERRIDE` redirects every CRM mail, and the CC goes with it: a CC left on the
 * message would still reach the real address. Both originals stay readable in a header. Null and
 * '' both mean no override.
 */
final class CrmMailer
{
    public const string ORIGINAL_RECIPIENT_HEADER = 'X-Crm-Original-Recipient';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly MarkdownRenderer $renderer,
        private readonly EnabledLocale $locales,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $from,
        #[Autowire('%env(default::INQUIRY_RECIPIENT_OVERRIDE)%')]
        private readonly ?string $recipientOverride,
    ) {
    }

    /**
     * @throws TransportExceptionInterface a transport error
     * @throws HandlerFailedException      any other failure in the mail pipeline
     * @throws RfcComplianceException      an address `Address` refuses at build time
     */
    public function send(string $to, ?string $cc, string $subject, string $markdown, string $senderName): void
    {
        $message = (new TemplatedEmail())
            ->from(new Address(Address::create($this->from)->getAddress(), $senderName))
            ->replyTo($this->contactEmail)
            ->subject($subject)
            ->text($markdown)
            ->htmlTemplate('admin/crm/email/message.html.twig')
            ->locale($this->locales->default())
            ->context(['body_html' => $this->renderer->render($markdown)]);

        $override = (string) $this->recipientOverride;
        if ('' !== $override) {
            $message->to($override);
            $message->getHeaders()->addTextHeader(self::ORIGINAL_RECIPIENT_HEADER, null === $cc ? $to : $to.', '.$cc);
        } else {
            $message->to($to);
            if (null !== $cc) {
                $message->cc($cc);
            }
        }

        $this->mailer->send($message);
    }
}
