<?php

declare(strict_types=1);

namespace App\Portal\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ClaimCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'claim.code.label',
            'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 12],
        ]);
        $builder->get('code')->addModelTransformer(new CallbackTransformer(
            static fn (?string $code): ?string => $code,
            static fn (?string $code): ?string => null === $code ? null : preg_replace('/\s+/u', '', $code),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClaimCodeData::class, 'translation_domain' => 'portal']);
    }
}
