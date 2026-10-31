<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Translation\EnabledLocale;
use App\Venues\ValueObject\FloorMaterial;
use App\Venues\ValueObject\Seating;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The room editor's JSON body (#25, D23): `names[<locale>]`, `descriptions[<locale>]` for every enabled
 * locale (named children, so no other key is accepted), the capacities, `areaM2`, `heightM` as text,
 * `floorMaterial`, `seating[<type>][min|max]`, and with `with_token` the room's `loadedAt`. Submitted
 * with `$form->submit($payload)`; a field left out is cleared, as the drawer sends its whole state.
 * No CSRF field: the JSON route checks the `X-CSRF-Token` header.
 */
final class RoomType extends AbstractType
{
    public const int NAME_MAX = 100;

    public const int DESCRIPTION_MAX = 5000;

    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_token']) {
            $builder->add('loadedAt', HiddenType::class);
        }

        $names = $builder->create('names', FormType::class, ['label' => false]);
        $descriptions = $builder->create('descriptions', FormType::class, ['label' => false]);
        foreach ($this->locales->all() as $locale) {
            $isDefault = $locale === $this->locales->default();
            $names->add($locale, TextType::class, [
                'label' => false,
                'required' => $isDefault,
                'constraints' => [
                    ...($isDefault ? [new Assert\NotBlank(message: 'cms.room.name.required')] : []),
                    new Assert\Length(max: self::NAME_MAX, maxMessage: 'cms.venue.too_long'),
                ],
            ]);
            $descriptions->add($locale, TextareaType::class, [
                'label' => false,
                'required' => false,
                'constraints' => [new Assert\Length(max: self::DESCRIPTION_MAX, maxMessage: 'cms.venue.too_long')],
            ]);
        }

        $seating = $builder->create('seating', FormType::class, ['label' => false]);
        foreach (Seating::cases() as $type) {
            $seating->add($type->value, RoomSeatingType::class, ['label' => false]);
        }

        $builder
            ->add($names)
            ->add($descriptions)
            ->add('capacityMin', RoomIntegerType::class)
            ->add('capacityMax', RoomIntegerType::class)
            ->add('areaM2', RoomIntegerType::class)
            ->add('heightM', DecimalTextType::class, ['label' => false, 'required' => false])
            ->add('floorMaterial', EnumType::class, [
                'class' => FloorMaterial::class,
                'label' => false,
                'required' => false,
                'invalid_message' => 'cms.room.floor.invalid',
                'invalid_message_parameters' => ['{{ value }}' => ''],
            ])
            ->add($seating);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomData::class,
            'translation_domain' => 'cms',
            'csrf_protection' => false,
            'with_token' => false,
            'validation_groups' => static fn (Options $options): array => $options['with_token'] ? ['Default', 'token'] : ['Default'],
        ]);
        $resolver->setAllowedTypes('with_token', 'bool');
    }
}
