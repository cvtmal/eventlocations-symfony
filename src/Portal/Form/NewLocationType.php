<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Admin\Form\ComboboxType;
use App\Geo\ValueObject\Canton;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NewLocationType extends AbstractType
{
    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'onboarding.new.field.name'])
            ->add('capacityMax', IntegerType::class, ['label' => 'onboarding.new.field.capacity'])
            ->add('town', ComboboxType::class, [
                'label' => 'onboarding.new.field.town',
                'url' => $this->urls->generate('portal_api_towns'),
                'free_text' => true,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('canton', EnumType::class, [
                'class' => Canton::class,
                'label' => 'onboarding.new.field.canton',
                'required' => false,
                'placeholder' => 'onboarding.new.placeholder.canton',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NewLocationData::class, 'translation_domain' => 'portal']);
    }
}
