<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * An optional whole number of the room editor (#25) with the `cms` message for a value that is no number.
 * `{{ value }}` is emptied: the ICU formatter cannot take a submitted value that is not valid UTF-8
 * (cross-cycle note #24 → #25).
 */
final class RoomIntegerType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => false,
            'required' => false,
            'invalid_message' => 'cms.room.number.invalid',
            'invalid_message_parameters' => ['{{ value }}' => ''],
        ]);
    }

    public function getParent(): string
    {
        return IntegerType::class;
    }
}
