<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ReadModel\CompanyListFilter;
use App\Crm\ReadModel\OwnerOption;
use App\Crm\ValueObject\VerbatimText;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The owner selects' options from `OwnerOptionsQuery`: the id as value, the name as label, verbatim.
 * A form receives the owners as its `owners` option, so the controller runs the one statement.
 */
final readonly class OwnerChoices
{
    private function __construct()
    {
    }

    /**
     * ChoiceType options for an owner select; `$withNone` adds "Ohne Zuständige" (`OWNER_NONE`) first.
     *
     * @param list<OwnerOption> $owners
     *
     * @return array{choices: array<string, string>, choice_label: \Closure(string): TranslatableInterface}
     */
    public static function options(array $owners, bool $withNone = false): array
    {
        $names = [];
        foreach ($owners as $owner) {
            $names[$owner->id] = $owner->name;
        }
        $values = [...($withNone ? [CompanyListFilter::OWNER_NONE] : []), ...array_keys($names)];

        return [
            'choices' => array_combine($values, $values),
            'choice_label' => static fn (string $value): TranslatableInterface => CompanyListFilter::OWNER_NONE === $value
                ? new TranslatableMessage('list.filter.owner_none', [], 'crm')
                : new VerbatimText($names[$value] ?? $value),
        ];
    }
}
