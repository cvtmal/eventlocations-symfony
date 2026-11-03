<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Staff add a location manager: an existing account by its address, or a new one, which gets an invitation.
 */
final class PortalManagerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'invite.panel.email', 'help' => 'invite.panel.email_help', 'attr' => ['autocomplete' => 'off']])
            ->add('firstName', TextType::class, ['label' => 'invite.panel.first_name', 'required' => false, 'attr' => ['autocomplete' => 'off']])
            ->add('lastName', TextType::class, ['label' => 'invite.panel.last_name', 'required' => false, 'attr' => ['autocomplete' => 'off']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PortalManagerData::class, 'translation_domain' => 'portal']);
    }

    public function getBlockPrefix(): string
    {
        return 'portal_manager';
    }
}
