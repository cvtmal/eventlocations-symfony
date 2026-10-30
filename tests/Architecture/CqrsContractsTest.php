<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Messenger\CommandHandlerInterface;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class CqrsContractsTest extends ArchitectureRules
{
    public function test_command_handlers_implement_the_marker(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('CommandHandler'))
            ->excluding(Selector::isInterface(), Selector::isAbstract())
            ->should()->implement()
            ->classes(Selector::classname(CommandHandlerInterface::class))
            ->because('The marker is what registers a handler on command.bus.');
    }
}
