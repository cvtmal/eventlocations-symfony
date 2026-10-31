<?php

declare(strict_types=1);

namespace App\Venues;

use App\Shared\Translation\EnabledLocale;
use App\Venues\Entity\VenueDocument;
use App\Venues\Entity\VenueDocumentTranslation;
use App\Venues\Exception\VenueRuleViolation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Writes a document's titles (#25, CMS-7), shared by AddVenueDocument and RetitleVenueDocument:
 * trimmed; for each locale given, an empty title removes the locale's row, and the default
 * locale always keeps one (VenueRuleViolation::documentTitleRequired(), field
 * `titles.<locale>`). Every rule is checked before anything is written. A locale that is not
 * enabled is an \InvalidArgumentException (the form restricts the keys).
 */
final class DocumentTitles
{
    public function __construct(
        private readonly EnabledLocale $locales,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, string> $titles locale → title
     *
     * @return bool whether a title changed
     *
     * @throws VenueRuleViolation
     */
    public function write(VenueDocument $document, bool $isNew, array $titles): bool
    {
        $default = $this->locales->default();
        $wanted = $this->check($titles, $isNew);

        $existing = [];
        if (!$isNew) {
            foreach ($this->em->getRepository(VenueDocumentTranslation::class)->findBy(['document' => $document]) as $translation) {
                $existing[$translation->locale()] = $translation;
            }
        }
        if (!isset($wanted[$default]) && !isset($existing[$default])) {
            throw VenueRuleViolation::documentTitleRequired($default);
        }

        $changed = false;
        foreach ($wanted as $locale => $title) {
            $translation = $existing[$locale] ?? null;
            if ('' === $title) {
                if (null !== $translation) {
                    $this->em->remove($translation);
                    $changed = true;
                }
            } elseif (null === $translation) {
                $this->em->persist(new VenueDocumentTranslation($document, $locale, $title));
                $changed = true;
            } elseif ($translation->title() !== $title) {
                $translation->retitle($title);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * The trimmed titles, checked without touching the database: a handler calls it before its
     * first write, so a refused title leaves nothing in the unit of work. `$isNew` requires the
     * default locale's title to be given.
     *
     * @param array<string, string> $titles
     *
     * @return array<string, string>
     *
     * @throws VenueRuleViolation
     */
    public function check(array $titles, bool $isNew): array
    {
        $default = $this->locales->default();
        $wanted = [];
        foreach ($titles as $locale => $title) {
            $locale = (string) $locale;
            if (!$this->locales->isEnabled($locale)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not an enabled locale.', $locale));
            }
            $wanted[$locale] = trim($title);
            if ('' === $wanted[$locale] && $locale === $default) {
                throw VenueRuleViolation::documentTitleRequired($default);
            }
        }
        if ($isNew && !isset($wanted[$default])) {
            throw VenueRuleViolation::documentTitleRequired($default);
        }

        return $wanted;
    }
}
