<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueLocaleEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ambience', TextType::class, [
                'label' => 'event.field.ambience',
                'help' => 'event.help.ambience',
                'required' => false,
                'attr' => ['data-counter-max' => 60],
            ])
            ->add('capacityNote', TextareaType::class, ['label' => 'event.field.capacity_note', 'required' => false, 'attr' => ['rows' => 2, 'data-counter-max' => 250]])
            ->add('openingHours', TextareaType::class, ['label' => 'event.field.opening_hours', 'required' => false, 'attr' => ['rows' => 2, 'data-counter-max' => 250]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VenueLocaleEventData::class, 'translation_domain' => 'cms']);
    }
}
