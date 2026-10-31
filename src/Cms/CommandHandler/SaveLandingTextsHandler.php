<?php

declare(strict_types=1);

namespace App\Cms\CommandHandler;

use App\Cms\Command\SaveLandingTexts;
use App\Cms\ContentBlockWriter;
use App\Cms\Exception\ContentTooLong;
use App\Cms\Exception\StaleContentBlocks;
use App\Cms\Exception\UnknownLandingPage;
use App\Cms\ValueObject\LandingKey;
use App\Shared\Extension\LandingPageSource;
use App\Shared\Messenger\CommandHandlerInterface;
use App\Shared\Translation\EnabledLocale;
use App\Shared\ValueObject\LandingPageEntry;

/**
 * Writes a landing page's texts (#26): the page must exist (at least one published venue,
 * live or under the threshold), each text must fit its limit, then ContentBlockWriter checks
 * the version and writes. Its rows are `landing.<prefix>.<field>`.
 */
final class SaveLandingTextsHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly LandingPageSource $landingPages,
        private readonly ContentBlockWriter $writer,
        private readonly EnabledLocale $locales,
    ) {
    }

    /**
     * @throws UnknownLandingPage
     * @throws ContentTooLong
     * @throws StaleContentBlocks
     */
    public function __invoke(SaveLandingTexts $command): void
    {
        $entry = LandingKey::isValid($command->keyPrefix) ? $this->landingPages->page($command->keyPrefix) : null;
        if (null === $entry) {
            throw UnknownLandingPage::forKey($command->keyPrefix);
        }

        $bodies = [];
        foreach ($command->texts as $locale => $fields) {
            if (!$this->locales->isEnabled($locale)) {
                throw new \InvalidArgumentException(\sprintf('The locale "%s" is not enabled.', $locale));
            }
            foreach ($fields as $field => $text) {
                ContentTooLong::check($text, $field, $locale, LandingKey::maxLength($field));
                $bodies[$locale][$entry->blockKey($field)] = $text;
            }
        }

        $this->writer->apply(
            'landing.'.$entry->keyPrefix,
            array_map($entry->blockKey(...), LandingPageEntry::FIELDS),
            $bodies,
            $command->version,
        );
    }
}
