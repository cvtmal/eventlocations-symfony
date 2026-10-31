<?php

declare(strict_types=1);

namespace App\Cms\CommandHandler;

use App\Cms\Command\RefreshContentBlockSnapshot;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\ReadModel\ContentBlocks;
use Psr\Log\LoggerInterface;

final class RefreshContentBlockSnapshotHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContentBlocks $contentBlocks,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshContentBlockSnapshot $command): void
    {
        try {
            $this->contentBlocks->refresh();
        } catch (\Throwable $exception) {
            // The rows are committed by now: the save stands, the purge still has to run, and the
            // snapshot's age heals this within a day. The values go into placeholders, because the
            // default logger prints nothing else of the context, and never the exception's message.
            $this->logger->error('Content blocks: the snapshot refresh failed ({exception_class} at {file}:{line}).', [
                'exception_class' => $exception::class,
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);
        }
    }
}
