<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Admin\Form\ComboboxType;
use App\Crm\ValueObject\ContactKind;
use App\Crm\ValueObject\ContactProperty;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Kontakt erfassen" (CRM-2). The company is a combobox on `admin_api_crm_companies` (`company_url`),
 * showing `company_label` for a preset or re-rendered id; `owners` are the selectable staff accounts.
 */
final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'contact.property.first_name', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::FirstName))])
            ->add('lastName', TextType::class, ['label' => 'contact.property.last_name', 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::LastName))])
            ->add('kind', EnumType::class, ['class' => ContactKind::class, 'label' => 'contact.property.kind', 'constraints' => [new Assert\NotNull(message: 'crm.property.required')]])
            ->add('company', ComboboxType::class, [
                'label' => 'contact.property.company',
                'required' => false,
                'url' => $options['company_url'],
                'display_value' => $options['company_label'],
                'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Company)),
            ])
            ->add('position', TextType::class, ['label' => 'contact.property.position', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Position))])
            ->add('email', TextType::class, ['label' => 'contact.property.email', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Email)), 'attr' => ['inputmode' => 'email', 'autocomplete' => 'off']])
            ->add('phone', TextType::class, ['label' => 'contact.property.phone', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Phone)), 'attr' => ['inputmode' => 'tel', 'autocomplete' => 'off']])
            ->add('owner', ChoiceType::class, [
                'label' => 'contact.property.owner',
                'required' => false,
                'placeholder' => 'create.owner_none',
                ...OwnerChoices::options($options['owners']),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactData::class, 'translation_domain' => 'crm', 'company_label' => null]);
        $resolver->setRequired(['owners', 'company_url']);
        $resolver->setAllowedTypes('owners', 'array');
        $resolver->setAllowedTypes('company_url', 'string');
        $resolver->setAllowedTypes('company_label', ['null', 'string']);
    }
}
