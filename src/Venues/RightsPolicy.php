<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Markdown\MarkdownRenderer;
use App\Shared\ReadModel\ContentBlocks;
use App\Shared\Translation\EnabledLocale;
use App\Venues\Entity\RightsPolicyVersion;
use App\Venues\Extension\RightsDeclarationBlock;
use App\Venues\ValueObject\RightsPolicyText;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The declaration text in force (G1): the staff-edited content block `rights.declaration`, else
 * the catalogue default, in the default locale, and the version that text stands for.
 * `record()` keeps the words of a version in `rights_policy_versions` the first time a grant uses
 * it, so a grant's `policy_version` can always be read back.
 */
final class RightsPolicy
{
    /** The version of the grants the migration backfilled. */
    public const string LEGACY = 'legacy';

    public function __construct(
        private readonly ContentBlocks $blocks,
        private readonly TranslatorInterface $translator,
        private readonly EnabledLocale $locales,
        private readonly MarkdownRenderer $markdown,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function current(): RightsPolicyText
    {
        $locale = $this->locales->default();
        $text = trim($this->blocks->text(RightsDeclarationBlock::KEY, $locale) ?? '');
        if ('' === $text) {
            $text = trim($this->translator->trans(RightsDeclarationBlock::DEFAULT_MESSAGE, [], 'venues', $locale));
        }

        return RightsPolicyText::of($text);
    }

    /**
     * The text of `current()` as safe HTML, for the page that asks for the declaration. A page has
     * one h1, so `#` steps down to h2 as in every staff text on a page (`ContentBlocks`); with
     * `html_input: escape` that tag can only come from a heading.
     */
    public function currentHtml(): string
    {
        return (string) preg_replace('~<(/?)h1>~', '<$1h2>', $this->markdown->render($this->current()->markdown));
    }

    /**
     * The row of the text in force, written when it is new. The caller flushes.
     */
    public function record(RightsPolicyText $text): RightsPolicyVersion
    {
        $version = $this->em->find(RightsPolicyVersion::class, $text->version);
        if (null === $version) {
            $version = new RightsPolicyVersion($text->version, $text->markdown, $this->clock->now());
            $this->em->persist($version);
        }

        return $version;
    }

    public function legacy(): RightsPolicyVersion
    {
        return $this->em->getReference(RightsPolicyVersion::class, self::LEGACY) ?? throw new \LogicException('The legacy policy version is a migration row.');
    }
}
