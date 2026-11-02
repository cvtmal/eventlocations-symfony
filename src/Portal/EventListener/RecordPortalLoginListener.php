<?php

declare(strict_types=1);

namespace App\Portal\EventListener;

use App\Portal\Command\RecordPortalLogin;
use App\Portal\Security\PortalArea;
use App\Portal\Security\PortalIdentity;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
final class RecordPortalLoginListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (PortalArea::FIREWALL !== $event->getFirewallName() || !$user instanceof PortalIdentity) {
            return;
        }

        $this->commandBus->dispatch(new RecordPortalLogin($user->id()));
    }
}
