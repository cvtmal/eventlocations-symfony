<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The reset form's two new-password fields, plus the current password.
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, ['label' => 'profile.current_password', 'attr' => ['autocomplete' => 'current-password']]);
    }

    public function getParent(): string
    {
        return PortalPasswordType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ChangePasswordData::class]);
    }
}
