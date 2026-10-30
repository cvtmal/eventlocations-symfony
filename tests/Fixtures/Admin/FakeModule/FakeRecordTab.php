<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\RecordTab;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

final class FakeRecordTab implements RecordTab
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function label(): TranslatableInterface
    {
        return new TranslatableMessage('tab.label', [], 'probe_admin');
    }

    public function route(): string
    {
        return 'admin_probe_record';
    }

    public function routeParameters(string $recordId): array
    {
        return ['id' => $recordId];
    }

    public function priority(): int
    {
        return 0;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
