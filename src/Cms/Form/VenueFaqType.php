<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ValueObject\TriState;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueFaqType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('daylight', EnumType::class, [
                'class' => TriState::class,
                'label' => 'faq.field.daylight',
                'required' => false,
                'expanded' => true,
                'placeholder' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueFaqData::class);
    }
}
