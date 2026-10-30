<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Admin\FakeModule;

use App\Admin\Extension\RecordPanel;

final class FakeRecordPanel implements RecordPanel
{
    public function recordType(): string
    {
        return 'venue';
    }

    public function template(): string
    {
        return '@admin_probe/panel.html.twig';
    }

    public function context(string $recordId): array
    {
        return ['record_id' => $recordId];
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
