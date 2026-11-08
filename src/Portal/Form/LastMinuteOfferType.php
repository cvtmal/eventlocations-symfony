<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Venues\ValueObject\LastMinuteLimits;
use App\Venues\ValueObject\Term;
use App\Venues\ValueObject\TermGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An offer «Kurzfristig frei» in `/konto` (G14). Options: `rooms` (id → name, the rooms the venue's page shows) and
 * `event_types` (the venue's «Geeignet für» codes), from `LastMinuteOffersQuery::choices()`.
 */
final class LastMinuteOfferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $rooms */
        $rooms = $options['rooms'];
        /** @var list<string> $eventTypes */
        $eventTypes = $options['event_types'];

        $builder
            ->add('startsOn', DateType::class, ['label' => 'last_minute.form.starts_on', 'widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('endsOn', DateType::class, [
                'label' => 'last_minute.form.ends_on',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'last_minute.form.ends_on_help',
                'help_translation_parameters' => ['days' => LastMinuteLimits::MAX_DAYS],
            ]);
        if ([] !== $rooms) {
            $builder->add('roomId', ChoiceType::class, [
                'label' => 'last_minute.form.room',
                'required' => false,
                'placeholder' => 'last_minute.form.whole_location',
                'choices' => array_flip($rooms),
                'choice_translation_domain' => false,
            ]);
        }
        if ([] !== $eventTypes) {
            $builder->add('eventTypes', ChoiceType::class, [
                'label' => 'last_minute.form.event_types',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine($eventTypes, $eventTypes),
                'choice_label' => static fn (string $code): Term => new Term(TermGroup::SuitableFor, $code),
            ]);
        }
        $builder
            ->add('priceText', TextType::class, [
                'label' => 'last_minute.form.price_text',
                'help' => 'last_minute.form.price_text_help',
                'attr' => ['maxlength' => LastMinuteLimits::PRICE_TEXT_MAX],
            ])
            ->add('minGuests', IntegerType::class, [
                'label' => 'last_minute.form.min_guests',
                'required' => false,
                'attr' => ['min' => 1, 'max' => LastMinuteLimits::MIN_GUESTS_MAX],
            ])
            ->add('terms', TextareaType::class, [
                'label' => 'last_minute.form.terms',
                'required' => false,
                'help' => 'last_minute.form.terms_help',
                'attr' => ['maxlength' => LastMinuteLimits::TERMS_MAX, 'rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LastMinuteOfferData::class,
            'translation_domain' => 'portal',
            'rooms' => [],
            'event_types' => [],
        ]);
        $resolver->setAllowedTypes('rooms', 'array');
        $resolver->setAllowedTypes('event_types', 'array');
    }
}
