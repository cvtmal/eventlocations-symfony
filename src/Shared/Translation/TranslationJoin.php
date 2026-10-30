<?php

declare(strict_types=1);

namespace App\Shared\Translation;

/**
 * Read side of content translations (see .claude/rules/i18n.md, "Content translations"):
 * joins the one translation row of an owner in the requested locale, or in the default
 * locale when that row is missing. The fallback is per row, so all translated fields of a
 * record come from the same language.
 *
 * Usage in a read model:
 *
 *     $sql = 'SELECT v.id, t.name, t.slug FROM venues v '
 *         .$join->localized('venue_translations', 'venue_id', 'v.id', 't')
 *         .' WHERE v.id = :id';
 *     $connection->fetchAssociative($sql, ['id' => $id] + $join->parameters($locale));
 *
 * The identifiers are interpolated into SQL, so they are validated; values travel as parameters.
 */
final readonly class TranslationJoin
{
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';
    private const QUALIFIED_IDENTIFIER = '/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/';

    public function __construct(
        private EnabledLocale $enabledLocale,
    ) {
    }

    /**
     * @param string $table      the translation table, e.g. `venue_translations`
     * @param string $foreignKey its column pointing at the owner, e.g. `venue_id`
     * @param string $ownerIdSql the owner's id column in the outer query, e.g. `v.id`
     * @param string $alias      the alias of the joined translation row, e.g. `t`
     */
    public function localized(string $table, string $foreignKey, string $ownerIdSql, string $alias): string
    {
        foreach (['table' => $table, 'foreign key' => $foreignKey, 'alias' => $alias] as $what => $identifier) {
            if (1 !== preg_match(self::IDENTIFIER, $identifier)) {
                throw new \InvalidArgumentException(\sprintf('Invalid %s "%s".', $what, $identifier));
            }
        }
        if (1 !== preg_match(self::QUALIFIED_IDENTIFIER, $ownerIdSql)) {
            throw new \InvalidArgumentException(\sprintf('Invalid owner id column "%s".', $ownerIdSql));
        }

        return <<<SQL
            LEFT JOIN LATERAL (
                SELECT tr.* FROM {$table} tr
                WHERE tr.{$foreignKey} = {$ownerIdSql} AND tr.locale IN (:i18n_locale, :i18n_fallback_locale)
                ORDER BY tr.locale = :i18n_locale DESC
                LIMIT 1
            ) {$alias} ON TRUE
            SQL;
    }

    /**
     * @return array{i18n_locale: string, i18n_fallback_locale: string}
     */
    public function parameters(string $locale): array
    {
        return ['i18n_locale' => $locale, 'i18n_fallback_locale' => $this->enabledLocale->default()];
    }
}
