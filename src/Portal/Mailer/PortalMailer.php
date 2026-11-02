<?php

declare(strict_types=1);

namespace App\Portal\Mailer;

use App\Portal\Entity\PortalUser;
use App\Portal\Security\PortalLinkSigner;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The account mails. They go to an address nobody has proved yet, so they quote nothing the visitor typed: a name
 * in the greeting would send a stranger any text from our domain. A dead mail server changes no answer, so a
 * failure is logged and swallowed: the visitor sees what everyone sees, and asking again is the recovery. The
 * log carries the account id and where the cause was raised, never its message, which can quote the address,
 * and never the token.
 */
final class PortalMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly PortalLinkSigner $links,
        private readonly LoggerInterface $logger,
        #[Autowire('%site.contact_email%')]
        private readonly string $contactEmail,
    ) {
    }

    public function sendRegistrationLink(PortalUser $user): void
    {
        $this->send($user, 'mail.register.subject', 'portal/email/register.html.twig', $this->links->registrationToken($user));
    }

    public function sendPasswordReset(PortalUser $user): void
    {
        $this->send($user, 'mail.reset.subject', 'portal/email/reset.html.twig', $this->links->resetToken($user));
    }

    private function send(PortalUser $user, string $subjectKey, string $template, string $token): void
    {
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($user->email())
                ->replyTo($this->contactEmail)
                ->subject($this->translator->trans($subjectKey, [], 'portal'))
                ->htmlTemplate($template)
                ->context(['token' => $token]));
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('Portal account {account}: the {template} mail failed ({exception_class} at {file}:{line}).', [
                'account' => $user->id(),
                'template' => $template,
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);
        }
    }
}
