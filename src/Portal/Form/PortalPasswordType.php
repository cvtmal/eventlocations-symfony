<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Portal\Security\PortalPasswordHasher;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PortalPasswordType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => $this->translator->trans('reset.error.password_mismatch', [], 'portal'),
            'first_options' => [
                'label' => 'reset.new_password',
                'help' => 'register.password_help',
                'help_translation_parameters' => ['min' => PortalPasswordHasher::MIN_LENGTH],
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => ['label' => 'reset.password_repeat', 'attr' => ['autocomplete' => 'new-password']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PortalPasswordData::class, 'translation_domain' => 'portal']);
    }
}
