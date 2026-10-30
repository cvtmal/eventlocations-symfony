<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The audit screen's filter: a GET form in the query string, so a filtered list has a URL.
 * `actors` maps display names to user ids.
 */
final class AuditLogFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('actor', ChoiceType::class, [
                'label' => 'audit.filter.actor',
                'required' => false,
                'placeholder' => 'audit.actor.all',
                'choices' => $options['actors'],
                'choice_translation_domain' => false,
            ])
            ->add('subject_type', TextType::class, ['label' => 'audit.filter.subject_type', 'required' => false, 'property_path' => 'subjectType'])
            ->add('subject_id', TextType::class, ['label' => 'audit.filter.subject_id', 'required' => false, 'property_path' => 'subjectId'])
            ->add('from', DateType::class, ['label' => 'audit.filter.from', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('to', DateType::class, ['label' => 'audit.filter.to', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AuditLogFilterData::class,
            'translation_domain' => 'admin',
            'method' => 'GET',
            'csrf_protection' => false,
            'allow_extra_fields' => true,
            'actors' => [],
        ]);
        $resolver->setAllowedTypes('actors', 'array');
    }
}
