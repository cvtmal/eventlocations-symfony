<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\EraseContact;
use App\Crm\ContactEraser;
use App\Shared\Messenger\CommandHandlerInterface;

final class EraseContactHandler implements CommandHandlerInterface
{
    public function __construct(private readonly ContactEraser $eraser)
    {
    }

    public function __invoke(EraseContact $command): void
    {
        $this->eraser->erase($command->contactId);
    }
}
