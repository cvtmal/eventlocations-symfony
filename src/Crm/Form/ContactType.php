<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\ContactProperty;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The add and edit dialogs of a Location's contact card (CRM-2, #55). The HTML attributes mirror the columns, so the
 * browser stops most mistakes before the submit; the server rules are `PropertyConstraints::contact()`.
 */
final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'contact.property.first_name', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::FirstName)), 'attr' => ['maxlength' => 150]])
            ->add('lastName', TextType::class, ['label' => 'contact.property.last_name', 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::LastName)), 'attr' => ['maxlength' => 150]])
            ->add('position', TextType::class, ['label' => 'contact.property.position', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Position)), 'attr' => ['maxlength' => 150]])
            ->add('email', EmailType::class, ['label' => 'contact.property.email', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Email)), 'attr' => ['maxlength' => 254, 'inputmode' => 'email', 'autocomplete' => 'off']])
            ->add('phone', TelType::class, ['label' => 'contact.property.phone', 'required' => false, 'constraints' => FieldRules::of(PropertyConstraints::contact(ContactProperty::Phone)), 'attr' => ['maxlength' => 40, 'inputmode' => 'tel', 'autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactData::class, 'translation_domain' => 'crm']);
    }
}
