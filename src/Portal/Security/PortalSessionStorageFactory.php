<?php

declare(strict_types=1);

namespace App\Portal\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageFactoryInterface;
use Symfony\Component\HttpFoundation\Session\Storage\SessionStorageInterface;

/**
 * Gives a /konto session a cookie name of its own, so a manager's and a staff member's session never share
 * one. The name must be on the storage before the session starts; the path comes later, from
 * PortalResponseHeadersListener, because the session listener writes the static `cookie_path` of /admin.
 */
#[AsDecorator('session.storage.factory')]
final class PortalSessionStorageFactory implements SessionStorageFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly SessionStorageFactoryInterface $inner,
        private readonly PortalArea $portalArea,
    ) {
    }

    public function createStorage(?Request $request): SessionStorageInterface
    {
        $storage = $this->inner->createStorage($request);
        if (null !== $request && $this->portalArea->contains($request)) {
            $storage->setName(PortalArea::SESSION_COOKIE);
        }

        return $storage;
    }
}
