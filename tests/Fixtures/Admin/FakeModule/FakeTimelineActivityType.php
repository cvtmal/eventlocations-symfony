<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\TimelineActivityType;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeTimelineActivityType implements TimelineActivityType
{
    public function code(): string
    {
        return 'fake_activity';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('timeline.label', [], 'probe_admin');
    }

    public function priority(): int
    {
        return 10_000;
    }

    public function requiredRole(): string
    {
        return 'ROLE_ADMIN';
    }

    public function composable(): bool
    {
        return false;
    }
}
