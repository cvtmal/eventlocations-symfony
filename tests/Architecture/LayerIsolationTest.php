<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class LayerIsolationTest extends ArchitectureRules
{
    public function test_controllers_do_not_depend_on_command_handlers(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Controller'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('CommandHandler'))
            ->because('Controllers dispatch commands on the bus instead of calling handlers.');
    }

    public function test_console_commands_do_not_depend_on_command_handlers(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Console'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('CommandHandler'))
            ->because('Console commands dispatch commands on the bus instead of calling handlers.');
    }

    public function test_command_handlers_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('CommandHandler'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('Controller'))
            ->because('Handlers must work without HTTP (CLI, worker, tests).');
    }

    public function test_commands_do_not_depend_on_entities_or_repositories(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Command'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('Entity'), self::layer('Repository'))
            ->because('Commands carry scalars and IDs, so they stay serialisable for async transports.');
    }

    public function test_entities_do_not_depend_on_application_layers(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Entity'))
            ->shouldNot()->dependOn()
            ->classes(self::layer('Controller'), self::layer('CommandHandler'), self::layer('Command'))
            ->because('The domain model must not know how it is used.');
    }

    public function test_controllers_do_not_construct_command_handlers(): Rule
    {
        return PHPat::rule()
            ->classes(self::layer('Controller'))
            ->shouldNot()->construct()
            ->classes(self::layer('CommandHandler'))
            ->because('Handlers are wired by the container and reached through the bus.');
    }
}
