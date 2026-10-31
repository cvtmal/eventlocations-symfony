<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\CompanyProperty;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Firma erfassen" (CRM-1). The fields are plain text inputs, so the browser adds no rule of its own;
 * `owners` are the selectable staff accounts (`OwnerOptionsQuery::selectable()`).
 */
final class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'company.property.name', 'constraints' => FieldRules::of(PropertyConstraints::company(CompanyProperty::Name)), 'attr' => ['data-counter-max' => 150]])
            ->add('website', TextType::class, ['label' => 'company.property.website', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::company(CompanyProperty::Website)), 'help' => 'create.help.website', 'attr' => ['inputmode' => 'url', 'autocomplete' => 'off']])
            ->add('phone', TextType::class, ['label' => 'company.property.phone', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::company(CompanyProperty::Phone)), 'attr' => ['inputmode' => 'tel', 'autocomplete' => 'off']])
            ->add('email', TextType::class, ['label' => 'company.property.email', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::company(CompanyProperty::Email)), 'attr' => ['inputmode' => 'email', 'autocomplete' => 'off']])
            ->add('owner', ChoiceType::class, [
                'label' => 'company.property.owner',
                'required' => false,
                'placeholder' => 'create.owner_none',
                ...OwnerChoices::options($options['owners']),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CompanyData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired('owners');
        $resolver->setAllowedTypes('owners', 'array');
    }
}
