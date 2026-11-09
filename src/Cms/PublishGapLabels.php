<?php

declare(strict_types=1);

namespace App\Cms;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The words of a refused publish (CMS-3, `VenuePublishGaps`): the checklist's gaps as nouns after one «Es fehlt»
 * (#45 F-m2), the draft rule's gaps of an entry nobody manages (F14, D54) as staff read them on their own:
 * «Fakten nicht geprüft», «Beschreibung fehlt». Shared by the publish button and the moderation `keep`.
 */
final readonly class PublishGapLabels
{
    private const array DRAFT_RULE = ['facts', 'description'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $missing gap codes in the rule's order
     */
    public function describe(array $missing): string
    {
        $nouns = [];
        $states = [];
        foreach ($missing as $code) {
            if (\in_array($code, self::DRAFT_RULE, true)) {
                $states[] = $this->translator->trans('publish.gap.'.$code, [], 'cms');
            } else {
                $nouns[] = $this->translator->trans('editor.checklist.item.'.$code, [], 'cms');
            }
        }

        $parts = [];
        if ([] !== $nouns) {
            $parts[] = $this->translator->trans('publish.error.lacks', ['missing' => implode(', ', $nouns)], 'cms');
        }
        if ([] !== $states) {
            $parts[] = implode(', ', $states);
        }

        return implode('. ', $parts);
    }
}
