<?php

declare(strict_types=1);

namespace App\Portal\CommandHandler;

use App\Portal\Command\SendSubscriptionReminder;
use App\Portal\Entity\SubscriptionReminder;
use App\Portal\Mailer\PortalMailer;
use App\Portal\ReadModel\SubscriptionReminderQuery;
use App\Portal\Security\SubscriptionCancelToken;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Venues\EntitlementResolver;
use App\Venues\Repository\SubscriptionRepository;
use App\Venues\ValueObject\KnownTiers;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The marker is written only after at least one mail went out, so a dead mail server costs a night, not the reminder.
 * The query re-checks under this transaction that the reminder is still due; the unique key is the backstop against
 * two runs at once (cron runs one).
 */
final class SendSubscriptionReminderHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly SubscriptionReminderQuery $reminders,
        private readonly EntitlementResolver $entitlements,
        private readonly SubscriptionCancelToken $tokens,
        private readonly PortalMailer $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SendSubscriptionReminder $command): bool
    {
        $now = new \DateTimeImmutable('@'.$this->clock->now()->getTimestamp());
        $reminder = $this->reminders->reminder($command->subscriptionId, $command->daysBefore, $now);
        if (null === $reminder || [] === $reminder->recipients) {
            return false;
        }
        $cancelUrl = $this->urls->generate('portal_subscription_cancel', ['token' => $this->tokens->forId($reminder->subscriptionId)], UrlGeneratorInterface::ABSOLUTE_URL);
        $paid = $this->entitlements->forTier($reminder->tierCode);
        $free = $this->entitlements->forTier(KnownTiers::FREE);

        $sent = false;
        foreach ($reminder->recipients as $recipient) {
            $sent = $this->mailer->sendSubscriptionReminder($recipient['email'], $recipient['name'], $reminder, $command->daysBefore, $cancelUrl, $paid, $free) || $sent;
        }
        if (!$sent) {
            return false;
        }
        $this->em->persist(new SubscriptionReminder($this->subscriptions->get($reminder->subscriptionId), $command->daysBefore, $now));
        $this->em->flush();

        return true;
    }
}
