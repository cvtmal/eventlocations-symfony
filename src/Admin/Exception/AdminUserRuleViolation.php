<?php

declare(strict_types=1);

namespace App\Admin\Exception;

use App\Shared\Exception\DomainException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * A staff-account rule was broken (ADM-1, ADM-2). The message is a key of the `admin` catalogue, so
 * the users screen and the console show the same German text. `field` names the form field the error
 * belongs to; null means the whole form.
 */
final class AdminUserRuleViolation extends DomainException
{
    private function __construct(string $translationKey, public readonly ?string $field = null)
    {
        parent::__construct($translationKey);
    }

    public static function emailTaken(): self
    {
        return new self('users.error.email_taken', 'email');
    }

    public static function cannotChangeOwnAccount(): self
    {
        return new self('users.error.own_account');
    }

    public static function lastActiveAdmin(): self
    {
        return new self('users.error.last_admin');
    }

    public static function passwordTooShort(): self
    {
        return new self('users.error.password_too_short', 'plainPassword');
    }

    public static function notFound(): self
    {
        return new self('users.error.not_found');
    }

    /**
     * The bus wraps what a handler throws. Returns the rule violation inside, or rethrows the original.
     */
    public static function unwrap(HandlerFailedException $failure): self
    {
        foreach ($failure->getWrappedExceptions(self::class) as $violation) {
            return $violation;
        }

        throw $failure;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::emailTaken(), self::cannotChangeOwnAccount(), self::lastActiveAdmin(), self::passwordTooShort(), self::notFound()];
    }

    public function translationKey(): string
    {
        return $this->getMessage();
    }
}
