<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Cms\Form\VenueCardType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueCapacityType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder->add('capacityMax', IntegerType::class, ['label' => 'basics.field.capacity_max', 'attr' => ['min' => 1, 'max' => 50000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueCapacityData::class);
    }
}
