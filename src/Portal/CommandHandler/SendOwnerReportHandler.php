<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\SendOwnerReport;
use App\Portal\Entity\OwnerReport;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\OwnerReportQuery;
use App\Portal\Security\ReportOptOutToken;
use App\Portal\ValueObject\OwnerReportLinks;
use App\Portal\ValueObject\OwnerReportOutcome;
use App\Portal\ValueObject\ReportMonth;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The send-log row is written only after at least one mail went out, so a dead mail server costs a night, not the
 * report. The query re-checks that the report is still due; the unique key is the backstop against two runs at once
 * (cron runs one).
 */
final class SendOwnerReportHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly OwnerReportQuery $reports,
        private readonly ReportOptOutToken $tokens,
        private readonly PortalMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SendOwnerReport $command): OwnerReportOutcome
    {
        $month = ReportMonth::fromString(substr($command->month, 0, 7));
        if (null === $month || $month->sql() !== $command->month) {
            throw new \InvalidArgumentException(\sprintf('A report month is a first day (Y-m-d), not "%s".', $command->month));
        }
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $report = $this->reports->report($command->venueId, $month, $now);
        $venue = null === $report ? null : $this->em->find(Venue::class, $report->venueId);
        if (null === $report || null === $venue || [] === $report->recipients) {
            return OwnerReportOutcome::Skipped;
        }

        $number = ['number' => $report->venueNumber];
        $anchor = $report->suggestion->anchor();
        $links = new OwnerReportLinks(
            $this->urls->generate('portal_location', $number, UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate($report->suggestion->route(), $number + (null === $anchor ? [] : ['_fragment' => $anchor]), UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('portal_location', $number + ['_fragment' => 'location-plan'], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('portal_report_opt_out', ['token' => $this->tokens->forVenueId($report->venueId)], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        $sent = false;
        foreach ($report->recipients as $recipient) {
            $sent = $this->mailer->sendOwnerReport($recipient['email'], $recipient['name'], $report, $links) || $sent;
        }
        if (!$sent) {
            return OwnerReportOutcome::MailFailed;
        }
        $this->em->persist(new OwnerReport($venue, $month->firstDay(), $now));
        $this->em->flush();

        return OwnerReportOutcome::Sent;
    }
}
