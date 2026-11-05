<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Staff only: `Portal\ValueObject\LocationCard` has no such card. The template shows the switch only when the
 * loaded venue has a place ID; without one it is not rendered and submits as false.
 */
final class VenueGoogleType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('placeId', TextType::class, [
                'label' => 'google.field.place_id',
                'help' => 'google.help.place_id',
                'required' => false,
                'attr' => ['spellcheck' => 'false', 'autocomplete' => 'off'],
            ])
            ->add('ratingHidden', CheckboxType::class, [
                'label' => 'google.field.hidden',
                'help' => 'google.help.hidden',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueGoogleData::class);
    }
}
