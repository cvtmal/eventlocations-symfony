<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Venues\ValueObject\RatingHighlight;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VenueRatingType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('score', DecimalTextType::class, ['label' => 'rating.field.score', 'help' => 'rating.help.score', 'required' => false])
            ->add('count', IntegerType::class, ['label' => 'rating.field.count', 'required' => false, 'attr' => ['min' => 0]])
            ->add('highlights', EnumType::class, [
                'class' => RatingHighlight::class,
                'label' => 'rating.field.highlights',
                'help' => 'rating.help.highlights',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'attr' => ['class' => 'chips'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueRatingData::class);
    }
}
