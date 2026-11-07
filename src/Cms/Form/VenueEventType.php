<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\TermOptionsQuery;
use App\Venues\ValueObject\PriceLevel;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Veranstaltung card. The texts are named children per locale with a row, `texts[<locale>]`; the term
 * chips of amenity, catering and language follow.
 */
final class VenueEventType extends VenueCardType
{
    public function __construct(
        private readonly TermOptionsQuery $termOptions,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('priceLevel', EnumType::class, [
                'class' => PriceLevel::class,
                'label' => 'event.field.price_level',
                'required' => false,
                'expanded' => true,
                'placeholder' => 'event.placeholder.unknown',
            ])
            ->add('capacitySeated', IntegerType::class, ['label' => 'event.field.capacity_seated', 'required' => false, 'attr' => ['min' => 1, 'max' => 50000]])
            ->add('capacityStanding', IntegerType::class, ['label' => 'event.field.capacity_standing', 'required' => false, 'attr' => ['min' => 1, 'max' => 50000]]);

        $texts = $builder->create('texts', FormType::class, ['label' => false]);
        foreach (array_keys($options['venue']->translations) as $locale) {
            $texts->add((string) $locale, VenueLocaleEventType::class, ['label' => false]);
        }
        $builder->add($texts);

        $byGroup = $this->termOptions->byGroup();
        foreach (VenueEventData::GROUPS as $group) {
            TermChoices::add($builder, $group, $byGroup[$group->value] ?? []);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueEventData::class);
    }
}
