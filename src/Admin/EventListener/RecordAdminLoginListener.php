<?php

declare(strict_types=1);

namespace App\Admin\EventListener;

use App\Admin\Command\RecordAdminLogin;
use App\Admin\Security\AdminIdentity;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Records a login once it is complete. scheb's check path runs through the authenticator manager too, so
 * this event also fires for the code step, with the unwrapped token; after the password step of an
 * enrolled user the token is still a TwoFactorToken, which is skipped.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class RecordAdminLoginListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ('admin' !== $event->getFirewallName() || !$user instanceof AdminIdentity || $event->getAuthenticatedToken() instanceof TwoFactorTokenInterface) {
            return;
        }

        $this->commandBus->dispatch(new RecordAdminLogin($user->id()));
    }
}
