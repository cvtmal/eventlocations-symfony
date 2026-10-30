<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Admin\Form\ComboboxType;
use App\Geo\ValueObject\Canton;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The address card. `useTownCentre` is a submit button; a listener copies its press into the data before
 * validation, so the rules and `toCommand()` see it. The template must put a nameless default submit first
 * (P4), or Enter in any field would press it.
 */
final class VenueAddressType extends VenueCardType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        $builder
            ->add('town', ComboboxType::class, [
                'label' => 'address.field.town',
                'help' => 'address.help.town',
                'required' => false,
                'url' => $options['town_lookup_url'],
                'free_text' => true,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('canton', EnumType::class, [
                'class' => Canton::class,
                'label' => 'address.field.canton',
                'required' => false,
                'placeholder' => 'address.placeholder.canton',
            ])
            ->add('street', TextType::class, ['label' => 'address.field.street', 'required' => false])
            ->add('lat', DecimalTextType::class, ['label' => 'address.field.lat', 'required' => false])
            ->add('lng', DecimalTextType::class, ['label' => 'address.field.lng', 'required' => false])
            ->add('useTownCentre', SubmitType::class, ['label' => 'address.action.town_centre']);

        foreach (['station', 'highway', 'airport'] as $kind) {
            $builder
                ->add($kind.'Km', DecimalTextType::class, ['label' => 'address.field.'.$kind.'_km', 'required' => false])
                ->add($kind.'Name', TextType::class, ['label' => 'address.field.'.$kind.'_name', 'required' => false]);
        }

        if (VenueAddressData::showsRegions($options['venue'])) {
            $regions = $builder->create('regions', FormType::class, ['label' => false]);
            foreach (array_keys($options['venue']->translations) as $locale) {
                $regions->add((string) $locale, TextType::class, [
                    'label' => 'address.field.region',
                    'help' => 'address.help.region',
                    'required' => false,
                    'constraints' => [new Length(max: 60, maxMessage: 'cms.venue.too_long')],
                    'attr' => ['data-counter-max' => 60],
                ]);
            }
            $builder->add($regions);
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            $button = $event->getForm()->get('useTownCentre');
            if ($data instanceof VenueAddressData && $button instanceof SubmitButton) {
                $data->useTownCentre = $button->isClicked();
            }
        }, 1);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'data_class' => VenueAddressData::class,
            'town_lookup_url' => null,
        ]);
        $resolver->setAllowedTypes('town_lookup_url', ['null', 'string']);
    }
}
