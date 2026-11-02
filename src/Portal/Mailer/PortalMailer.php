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
 * The account mails go to an address nobody has proved yet, so they quote nothing the visitor typed: a name in
 * the greeting would send a stranger any text from our domain. A dead mail server changes no answer, so a
 * failure is logged and swallowed: the visitor sees what everyone sees, and asking again is the recovery. The
 * log carries the account id and where the cause was raised, never its message, which can quote the address,
 * and never the token.
 *
 * The access mails greet by name: asking needs a login, and only the mail's link sets a password, so that
 * address is proved. They answer whether the mail went, so staff's screen can say when it did not.
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

    public function sendAccessApproved(string $email, string $firstName, string $venueName): bool
    {
        return $this->sendAccessMail($email, $firstName, $venueName, 'claims.mail.approved.subject', 'portal/email/access_approved.html.twig');
    }

    public function sendAccessRejected(string $email, string $firstName, string $venueName): bool
    {
        return $this->sendAccessMail($email, $firstName, $venueName, 'claims.mail.rejected.subject', 'portal/email/access_rejected.html.twig');
    }

    public function sendLocationSubmitted(string $email, string $firstName, string $venueName): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.submitted.subject', $venueName, 'portal/email/location_submitted.html.twig', [
            'firstName' => $firstName,
        ]);
    }

    public function sendLocationNotice(string $venueName, string $managerName, string $managerEmail): bool
    {
        return $this->sendLocationMail($this->contactEmail, 'onboarding.mail.notice.subject', $venueName, 'portal/email/location_notice.html.twig', [
            'managerName' => $managerName,
            'managerEmail' => $managerEmail,
        ]);
    }

    public function sendLocationAccepted(string $email, string $firstName, string $venueName, int $number): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.accepted.subject', $venueName, 'portal/email/location_accepted.html.twig', [
            'firstName' => $firstName,
            'number' => $number,
        ]);
    }

    public function sendLocationRejected(string $email, string $firstName, string $venueName, int $number, string $reason): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.rejected.subject', $venueName, 'portal/email/location_rejected.html.twig', [
            'firstName' => $firstName,
            'number' => $number,
            'reason' => $reason,
        ]);
    }

    private function sendAccessMail(string $email, string $firstName, string $venueName, string $subjectKey, string $template): bool
    {
        return $this->sendLocationMail($email, $subjectKey, $venueName, $template, ['firstName' => $firstName]);
    }

    /**
     * These mails greet by name: creating a location needs a login, so the address is proved. They answer whether the
     * mail went, so a controller can tell staff when it did not.
     *
     * @param array<string, int|string> $context
     */
    private function sendLocationMail(string $to, string $subjectKey, string $venueName, string $template, array $context): bool
    {
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($to)
                ->replyTo($this->contactEmail)
                ->subject($this->translator->trans($subjectKey, ['venue' => $venueName], 'portal'))
                ->htmlTemplate($template)
                ->context(['venueName' => $venueName] + $context));

            return true;
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('The portal mail {template} failed ({exception_class} at {file}:{line}).', [
                'template' => $template,
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);

            return false;
        }
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
