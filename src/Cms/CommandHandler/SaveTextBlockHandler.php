<?php

declare(strict_types=1);

namespace App\Cms\CommandHandler;

use App\Cms\Command\SaveTextBlock;
use App\Cms\ContentBlockWriter;
use App\Cms\Exception\ContentTooLong;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Exception\UnknownContentBlock;
use App\Shared\Extension\ContentBlockRegistry;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;

/**
 * Writes a declared text block (#26): the key must be declared by a ContentBlockDefinition,
 * each locale's body must fit the definition's maxLength(), then ContentBlockWriter checks the
 * version and writes.
 */
final class SaveTextBlockHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly ContentBlockRegistry $registry,
        private readonly ContentBlockWriter $writer,
        private readonly EnabledLocale $locales,
    ) {
    }

    /**
     * @throws UnknownContentBlock
     * @throws ContentTooLong
     * @throws StaleContentBlocks
     */
    public function __invoke(SaveTextBlock $command): void
    {
        $definition = $this->registry->get($command->key) ?? throw UnknownContentBlock::forKey($command->key);

        $bodies = [];
        foreach ($command->bodies as $locale => $body) {
            if (!$this->locales->isEnabled($locale)) {
                throw new \InvalidArgumentException(\sprintf('The locale "%s" is not enabled.', $locale));
            }
            ContentTooLong::check($body, 'body', $locale, $definition->maxLength());
            $bodies[$locale][$definition->key()] = $body;
        }

        $this->writer->apply($definition->key(), [$definition->key()], $bodies, $command->version);
    }
}
