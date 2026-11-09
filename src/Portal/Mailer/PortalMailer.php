<?php

declare(strict_types=1);

namespace App\Portal\Mailer;

use App\Portal\Entity\PortalUser;
use App\Portal\ReadModel\OwnerReport;
use App\Portal\ReadModel\SubscriptionReminderRow;
use App\Portal\Security\PortalLinkSigner;
use App\Portal\ValueObject\OwnerReportLinks;
use App\Shared\Mailer\ReplyTo;
use App\Venues\ValueObject\Entitlements;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Address;
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

    /**
     * Tells staff, at `site.contact_email`, that a manager asked for access to a location (`ClaimController`,
     * `ClaimFlowController`). Reply-To is the manager, so staff answer with one click.
     */
    public function sendAccessRequestNotice(string $venueName, string $managerName, string $managerEmail): bool
    {
        return $this->sendLocationMail($this->contactEmail, 'claims.mail.notice.subject', $venueName, 'portal/email/access_notice.html.twig', [
            'managerName' => $managerName,
            'managerEmail' => $managerEmail,
        ], ReplyTo::person($managerEmail, $managerName));
    }

    /**
     * The invitation of an account staff created, or of one that has no password yet. It greets by the name staff
     * typed, not by anything a visitor sent. False when the mail did not go, or the account is gone or deactivated.
     */
    public function sendInvitation(string $portalUserId, string $email, string $firstName, string $venueName): bool
    {
        $token = $this->links->invitationToken($portalUserId);
        if (null === $token) {
            return false;
        }

        return $this->sendLocationMail($email, 'invite.mail.invitation.subject', $venueName, 'portal/email/invitation.html.twig', [
            'firstName' => $firstName,
            'token' => $token,
        ]);
    }

    /**
     * An account with a password that staff made a manager of a location.
     */
    public function sendManagerAdded(string $email, string $firstName, string $venueName): bool
    {
        return $this->sendAccessMail($email, $firstName, $venueName, 'invite.mail.added.subject', 'portal/email/manager_added.html.twig');
    }

    public function sendLocationSubmitted(string $email, string $firstName, string $venueName): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.submitted.subject', $venueName, 'portal/email/location_submitted.html.twig', [
            'firstName' => $firstName,
        ]);
    }

    /**
     * Tells staff, at `site.contact_email`, that a manager submitted a new location (`WizardController`). Reply-To is
     * the manager.
     */
    public function sendLocationNotice(string $venueName, string $managerName, string $managerEmail): bool
    {
        return $this->sendLocationMail($this->contactEmail, 'onboarding.mail.notice.subject', $venueName, 'portal/email/location_notice.html.twig', [
            'managerName' => $managerName,
            'managerEmail' => $managerEmail,
        ], ReplyTo::person($managerEmail, $managerName));
    }

    /**
     * The proof of publication (G6): the public page, the settings and how to take the entry off again. `$slug` ''
     * leaves the public link out.
     */
    public function sendLocationAccepted(string $email, string $firstName, string $venueName, int $number, string $slug): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.accepted.subject', $venueName, 'portal/email/location_accepted.html.twig', [
            'firstName' => $firstName,
            'number' => $number,
            'slug' => $slug,
        ]);
    }

    /**
     * G6: the code that proves a claimant's address. The address is unproved, so the mail greets with a plain
     * "Hallo" and carries nothing the visitor typed; the code is never logged.
     */
    public function sendClaimCode(string $email, string $code, string $venueName): void
    {
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($email)
                ->replyTo($this->contactEmail)
                ->subject($this->translator->trans('claim.mail.code.subject', ['venue' => $venueName], 'portal'))
                ->htmlTemplate('portal/email/claim_code.html.twig')
                ->context(['code' => $code, 'venueName' => $venueName]));
        } catch (TransportExceptionInterface|RfcComplianceException|HandlerFailedException $exception) {
            $cause = $exception instanceof HandlerFailedException ? $exception->getPrevious() ?? $exception : $exception;
            $this->logger->error('The claim code mail failed ({exception_class} at {file}:{line}).', [
                'exception_class' => $cause::class,
                'file' => $cause->getFile(),
                'line' => $cause->getLine(),
            ]);
        }
    }

    public function sendLocationRejected(string $email, string $firstName, string $venueName, int $number, string $reason): bool
    {
        return $this->sendLocationMail($email, 'onboarding.mail.rejected.subject', $venueName, 'portal/email/location_rejected.html.twig', [
            'firstName' => $firstName,
            'number' => $number,
            'reason' => $reason,
        ]);
    }

    /**
     * The reminder before a paid term ends (G9), 30 or 7 days ahead: the price of the next year, the cancellation
     * link and what stops on Free. A contract mail, so it carries no opt-out link (Wave G §4a.6); cancelling stops
     * the reminders. `$name` is null when it goes to the location's inquiry address.
     */
    public function sendSubscriptionReminder(string $to, ?string $name, SubscriptionReminderRow $reminder, int $daysBefore, string $cancelUrl, Entitlements $paid, Entitlements $free): bool
    {
        $template = 'portal/email/subscription_reminder.html.twig';
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($to)
                ->replyTo($this->contactEmail)
                ->subject($this->translator->trans(7 === $daysBefore ? 'subscription.mail.subject_7' : 'subscription.mail.subject_30', [
                    'venue' => $reminder->venueName,
                    'tier' => $reminder->tierName,
                    'end' => $reminder->endsOn,
                ], 'portal'))
                ->htmlTemplate($template)
                ->context([
                    'name' => $name,
                    'reminder' => $reminder,
                    'cancelUrl' => $cancelUrl,
                    'paid' => $paid,
                    'free' => $free,
                ]));

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

    /**
     * G14: an offer «Kurzfristig frei» passed its last day. A service mail about the manager's own content, so no
     * opt-out; the button opens the tab (the login sends the manager back there).
     */
    public function sendLastMinuteExpired(string $email, string $firstName, string $venueName, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, string $tabUrl): bool
    {
        return $this->sendLocationMail($email, 'last_minute.mail.expired.subject', $venueName, 'portal/email/last_minute_expired.html.twig', [
            'firstName' => $firstName,
            'startsOn' => $startsOn,
            'endsOn' => $endsOn,
            'tabUrl' => $tabUrl,
        ]);
    }

    /**
     * The monthly report of one entry (G10). Not a contract mail, so it carries the per-venue opt-out (Wave G §4a.6):
     * the link in the body and RFC 8058's `List-Unsubscribe` + `List-Unsubscribe-Post` headers for the one-click
     * button of the mail client. Whether Infomaniak's DKIM signature covers those headers is unverified, so the
     * in-body link is the path that always works. `$name` is null when it goes to an inquiry address.
     */
    public function sendOwnerReport(string $to, ?string $name, OwnerReport $report, OwnerReportLinks $links): bool
    {
        $template = 'portal/email/owner_report.html.twig';
        try {
            $email = (new TemplatedEmail())
                ->to($to)
                ->replyTo($this->contactEmail)
                ->subject($this->translator->trans('report.mail.subject', [
                    'venue' => $report->venueName,
                    'month' => $report->month->label(),
                ], 'portal'))
                ->htmlTemplate($template)
                ->context([
                    'name' => $name,
                    'report' => $report,
                    'links' => $links,
                ]);
            $email->getHeaders()
                ->addTextHeader('List-Unsubscribe', '<'.$links->optOut.'>')
                ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $this->mailer->send($email);

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

    private function sendAccessMail(string $email, string $firstName, string $venueName, string $subjectKey, string $template): bool
    {
        return $this->sendLocationMail($email, $subjectKey, $venueName, $template, ['firstName' => $firstName]);
    }

    /**
     * These mails greet by name: creating a location needs a login, so the address is proved. They answer whether the
     * mail went, so a controller can tell staff when it did not. `$replyTo` null replies to `site.contact_email`.
     *
     * @param array<string, int|string|\DateTimeImmutable> $context
     */
    private function sendLocationMail(string $to, string $subjectKey, string $venueName, string $template, array $context, ?Address $replyTo = null): bool
    {
        try {
            $this->mailer->send((new TemplatedEmail())
                ->to($to)
                ->replyTo($replyTo ?? $this->contactEmail)
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
