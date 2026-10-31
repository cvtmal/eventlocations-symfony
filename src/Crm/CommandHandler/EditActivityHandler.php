<?php

declare(strict_types=1);

namespace App\Crm\CommandHandler;

use App\Crm\Command\EditActivity;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\OwnActivity;
use App\Crm\ValueObject\PropertyValue;
use App\Shared\Messenger\CommandHandlerInterface;

final class EditActivityHandler implements CommandHandlerInterface
{
    public function __construct(private readonly OwnActivity $own)
    {
    }

    public function __invoke(EditActivity $command): void
    {
        $activity = $this->own->lock($command->id, $command->actingUserId);
        $body = PropertyValue::canonical($command->body) ?? throw InvalidPropertyValue::required('body');

        if ($body !== $activity->body() || $command->occurredAt->getTimestamp() !== $activity->occurredAt()->getTimestamp()) {
            $activity->rewrite($body, $command->occurredAt);
        }
    }
}
