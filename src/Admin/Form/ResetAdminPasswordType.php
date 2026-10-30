<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResetAdminPasswordType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => $this->translator->trans('users.error.password_mismatch', [], 'admin'),
            'first_options' => ['label' => 'users.form.new_password', 'help' => 'users.form.password_help', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'users.form.password_repeat', 'attr' => ['autocomplete' => 'new-password']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ResetAdminPasswordData::class, 'translation_domain' => 'admin']);
    }
}
