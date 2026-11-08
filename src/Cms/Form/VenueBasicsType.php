<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\ReadModel\TierOptionsQuery;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueBasicsType extends VenueCardType
{
    public function __construct(
        private readonly TierOptionsQuery $tiers,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('name', TextType::class, ['label' => 'basics.field.name', 'attr' => ['data-counter-max' => 150]])
            ->add('slug', TextType::class, [
                'label' => 'basics.field.slug',
                'help' => 'basics.help.slug',
                'required' => null !== $options['venue']->header->slug,
            ])
            ->add('tier', ChoiceType::class, ['choices' => $this->tiers->choices(), 'choice_translation_domain' => false, 'label' => 'basics.field.tier'])
            ->add('capacityMin', IntegerType::class, ['label' => 'basics.field.capacity_min', 'required' => false, 'attr' => ['min' => 1, 'max' => 50000]])
            ->add('capacityMax', IntegerType::class, ['label' => 'basics.field.capacity_max', 'attr' => ['min' => 1, 'max' => 50000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueBasicsData::class);
    }
}
