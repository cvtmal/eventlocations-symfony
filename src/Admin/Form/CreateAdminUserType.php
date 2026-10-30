<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\ValueObject\AdminRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CreateAdminUserType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'users.form.email', 'attr' => ['autocomplete' => 'off']])
            ->add('displayName', TextType::class, ['label' => 'users.form.display_name'])
            ->add('role', EnumType::class, [
                'class' => AdminRole::class,
                'label' => 'users.form.role',
                'choice_label' => static fn (AdminRole $role) => $role->label(),
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => $this->translator->trans('users.error.password_mismatch', [], 'admin'),
                'first_options' => ['label' => 'users.form.password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'users.form.password_repeat', 'attr' => ['autocomplete' => 'new-password']],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CreateAdminUserData::class, 'translation_domain' => 'admin']);
    }
}
