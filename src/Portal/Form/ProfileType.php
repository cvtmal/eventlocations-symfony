<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Name and phone. The e-mail is no field here: staff change it.
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, ['label' => 'register.first_name', 'attr' => ['autocomplete' => 'given-name']])
            ->add('lastName', TextType::class, ['label' => 'register.last_name', 'attr' => ['autocomplete' => 'family-name']])
            ->add('phone', TextType::class, ['label' => 'profile.phone', 'required' => false, 'attr' => ['inputmode' => 'tel', 'autocomplete' => 'tel']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfileData::class, 'translation_domain' => 'portal']);
    }
}
