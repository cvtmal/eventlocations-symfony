<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ReadModel\TermOptionsQuery;
use App\Cms\ValueObject\TriState;
use App\Venues\ValueObject\PartyAllowed;
use App\Venues\ValueObject\PriceLevel;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Hochzeit card. The texts are named children per locale with a row, `texts[<locale>]`; the curfew is
 * a single `HH:MM` field read and written as `H:i`.
 */
final class VenueWeddingType extends VenueCardType
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
                'label' => 'wedding.field.price_level',
                'required' => false,
                'expanded' => true,
                'placeholder' => 'wedding.placeholder.unknown',
            ])
            ->add('capacitySeated', IntegerType::class, ['label' => 'wedding.field.capacity_seated', 'required' => false, 'attr' => ['min' => 1, 'max' => 50000]])
            ->add('capacityStanding', IntegerType::class, ['label' => 'wedding.field.capacity_standing', 'required' => false, 'attr' => ['min' => 1, 'max' => 50000]])
            ->add('party', EnumType::class, [
                'class' => PartyAllowed::class,
                'label' => 'wedding.field.party',
                'required' => false,
                'expanded' => true,
                'placeholder' => 'wedding.placeholder.unknown',
            ])
            ->add('hasCurfew', EnumType::class, [
                'class' => TriState::class,
                'label' => 'wedding.field.has_curfew',
                'required' => false,
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('curfewAt', TimeType::class, [
                'label' => 'wedding.field.curfew_at',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
                'invalid_message' => 'cms.venue.curfew.format',
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ]);

        // With `input: string` an empty field comes back as '', which is no `H:i`.
        $builder->get('curfewAt')->addModelTransformer(new CallbackTransformer(
            static fn (?string $time): ?string => $time,
            static fn (?string $time): ?string => '' === $time ? null : $time,
        ));

        $texts = $builder->create('texts', FormType::class, ['label' => false]);
        foreach (array_keys($options['venue']->translations) as $locale) {
            $texts->add((string) $locale, VenueLocaleWeddingType::class, ['label' => false]);
        }
        $builder->add($texts);

        $byGroup = $this->termOptions->byGroup();
        foreach (VenueWeddingData::GROUPS as $group) {
            TermChoices::add($builder, $group, $byGroup[$group->value] ?? []);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefault('data_class', VenueWeddingData::class);
    }
}
