<?php

declare(strict_types=1);

namespace App\Admin\Messenger;

use App\Admin\Entity\AdminUser;
use App\Admin\Entity\AuditLogEntry;
use App\Admin\Security\AdminIdentity;
use App\Admin\ValueObject\AuditActorType;
use App\Shared\Messenger\AuditableCommand;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Writes one admin_audit_log row for every AuditableCommand that succeeded (S12, ADM-3). It sits inside
 * doctrine_transaction, which flushes and commits, so the row stands or falls with the change itself.
 */
final class AuditLogMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $envelope = $stack->next()->handle($envelope, $stack);
        $message = $envelope->getMessage();

        if ($message instanceof AuditableCommand) {
            $subject = $message->auditSubject();
            [$actorType, $actor] = $this->actor();

            $this->em->persist(new AuditLogEntry(
                $actorType,
                $actor,
                $message::class,
                $subject->type,
                $subject->id,
                $message->auditSummary(),
                $this->clock->now(),
            ));
        }

        return $envelope;
    }

    /**
     * A request is the only reliable sign of the web: PHP_SAPI is `cli` inside application tests too.
     *
     * @return array{AuditActorType, ?AdminUser}
     */
    private function actor(): array
    {
        $user = $this->security->getUser();
        if ($user instanceof AdminIdentity) {
            return [AuditActorType::AdminUser, $this->em->getReference(AdminUser::class, $user->id())];
        }

        return [null === $this->requests->getMainRequest() ? AuditActorType::Console : AuditActorType::System, null];
    }
}
