<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One seating type's range of a room (`seating[<type>][min|max]`, #25); min ≤ max is `RoomData`'s rule.
 */
final class RoomSeatingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['min', 'max'] as $bound) {
            $builder->add($bound, RoomIntegerType::class, [
                'constraints' => [new Assert\Range(notInRangeMessage: 'cms.room.capacity.range', min: 1, max: 50000)],
            ]);
        }
    }
}
