<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueLocaleWeddingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ambience', TextType::class, [
                'label' => 'wedding.field.ambience',
                'help' => 'wedding.help.ambience',
                'required' => false,
                'attr' => ['data-counter-max' => 60],
            ])
            ->add('capacityNote', TextareaType::class, ['label' => 'wedding.field.capacity_note', 'required' => false, 'attr' => ['rows' => 2, 'data-counter-max' => 250]])
            ->add('partyNote', TextareaType::class, ['label' => 'wedding.field.party_note', 'required' => false, 'attr' => ['rows' => 2, 'data-counter-max' => 250]])
            ->add('openingHours', TextareaType::class, ['label' => 'wedding.field.opening_hours', 'required' => false, 'attr' => ['rows' => 2, 'data-counter-max' => 250]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VenueLocaleWeddingData::class, 'translation_domain' => 'cms']);
    }
}
