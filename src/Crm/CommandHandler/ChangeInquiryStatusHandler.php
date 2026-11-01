<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\ChangeInquiryStatus;
use App\Crm\Repository\InquiryLinkRepository;
use App\Shared\Messenger\CommandHandlerInterface;

final class ChangeInquiryStatusHandler implements CommandHandlerInterface
{
    public function __construct(private readonly InquiryLinkRepository $links)
    {
    }

    public function __invoke(ChangeInquiryStatus $command): void
    {
        $this->links->lock($command->inquiryId)->changeStatus($command->status);
    }
}
