<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\TermOption;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The chip group of one term group, shared by the Merkmale and Veranstaltung cards: codes in `position` order,
 * labels from `venues` (`term.<group>.<code>`), stored under `terms[<group>]` of the data class.
 */
final class TermChoices
{
    /**
     * @param list<TermOption> $options
     */
    public static function add(FormBuilderInterface $builder, TermGroup $group, array $options): void
    {
        $builder->add($group->value, ChoiceType::class, [
            'label' => $group,
            'property_path' => 'terms['.$group->value.']',
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'choices' => self::codes($options),
            'choice_label' => static fn (string $code): string => 'term.'.$group->value.'.'.$code,
            'choice_translation_domain' => 'venues',
            'attr' => ['class' => 'chips'],
        ]);
    }

    /**
     * @param list<TermOption> $options
     *
     * @return array<string, string>
     */
    public static function codes(array $options): array
    {
        $codes = array_map(static fn (TermOption $option): string => $option->code, $options);

        return array_combine($codes, $codes);
    }
}
