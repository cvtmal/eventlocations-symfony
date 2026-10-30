<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\TermOption;
use App\Cms\ReadModel\TermOptionsQuery;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Merkmale card. The building chips show the `primary_position` terms first (the listing's chips); the
 * view carries them in that order as `primary_building_codes`, so the template can put the rest in a
 * `<details>`. Main building and main setting are two selects under their chips (P15).
 */
final class VenueTermsType extends VenueCardType
{
    public function __construct(
        private readonly TermOptionsQuery $termOptions,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $byGroup = $this->termOptions->byGroup();
        foreach (VenueTermsData::GROUPS as $group) {
            TermChoices::add($builder, $group, $byGroup[$group->value] ?? []);
        }
        foreach (['mainBuilding' => TermGroup::Building, 'mainSetting' => TermGroup::Setting] as $name => $group) {
            $builder->add($name, ChoiceType::class, [
                'label' => 'terms.field.'.('mainBuilding' === $name ? 'main_building' : 'main_setting'),
                'required' => false,
                'placeholder' => 'terms.placeholder.main',
                'choices' => TermChoices::codes($byGroup[$group->value] ?? []),
                'choice_label' => static fn (string $code): string => 'term.'.$group->value.'.'.$code,
                'choice_translation_domain' => 'venues',
            ]);
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $primary = array_filter(
            $this->termOptions->byGroup()[TermGroup::Building->value] ?? [],
            static fn (TermOption $option): bool => null !== $option->primaryPosition,
        );
        usort($primary, static fn (TermOption $a, TermOption $b): int => $a->primaryPosition <=> $b->primaryPosition);
        $view->vars['primary_building_codes'] = array_map(static fn (TermOption $option): string => $option->code, $primary);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueTermsData::class);
    }
}
