<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\RefreshCompanyStages;
use App\Crm\CompanyStageSync;
use App\Shared\Messenger\CommandHandlerInterface;

final class RefreshCompanyStagesHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly CompanyStageSync $stageSync,
    ) {
    }

    public function __invoke(RefreshCompanyStages $command): int
    {
        return $this->stageSync->syncAll();
    }
}
