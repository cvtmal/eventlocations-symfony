<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class VenueLocaleTextsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('summary', TextareaType::class, [
                'label' => 'texts.field.summary',
                'help' => 'texts.help.summary',
                'required' => $options['summary_required'],
                'constraints' => $options['summary_required'] ? [new NotBlank(message: 'cms.venue.summary.required')] : [],
                'attr' => ['rows' => 3, 'data-counter-max' => 200],
            ])
            ->add('description', TextareaType::class, ['label' => 'texts.field.description', 'required' => false, 'attr' => ['rows' => 10]])
            ->add('metaDescription', TextareaType::class, [
                'label' => 'texts.field.meta_description',
                'help' => 'texts.help.meta_description',
                'required' => false,
                'attr' => ['rows' => 2, 'data-counter-max' => 160],
            ])
            ->add('parkingText', TextareaType::class, [
                'label' => 'texts.field.parking_text',
                'required' => false,
                'attr' => ['rows' => 3, 'data-counter-max' => 250],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VenueLocaleTextsData::class,
            'translation_domain' => 'cms',
            'summary_required' => false,
        ]);
        $resolver->setAllowedTypes('summary_required', 'bool');
    }
}
