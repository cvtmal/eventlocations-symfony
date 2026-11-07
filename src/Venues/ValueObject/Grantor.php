<?php

declare(strict_types=1);

namespace App\Venues\ValueObject;

/**
 * The person or process behind a grant or its revocation (G1): a type and an opaque reference
 * (the portal user's or admin user's id, or a label such as `import`). It is a string on purpose:
 * the grant has no key into Admin or Portal, so neither module is a dependency of Venues.
 */
final readonly class Grantor
{
    public const int REF_MAX_LENGTH = 64;

    public function __construct(
        public GrantorType $type,
        public string $ref,
    ) {
        if ('' === $ref || mb_strlen($ref) > self::REF_MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('A grantor reference has 1 to %d characters.', self::REF_MAX_LENGTH));
        }
    }

    public static function manager(string $portalUserId): self
    {
        return new self(GrantorType::Manager, $portalUserId);
    }

    public static function staff(string $adminUserId): self
    {
        return new self(GrantorType::Staff, $adminUserId);
    }

    public static function system(string $label): self
    {
        return new self(GrantorType::System, $label);
    }
}
