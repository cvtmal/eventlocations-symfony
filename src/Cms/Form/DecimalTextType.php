<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A decimal kept as the string the database printed (P5): `NumberType` formats through the `de` locale to
 * three places, so `47.269412` would show as `47.269` and an idle save would round it. A comma is read as
 * the decimal point; the data class's rules check the shape and range.
 */
final class DecimalTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): ?string => $value,
            static fn (?string $value): ?string => null === $value ? null : str_replace(',', '.', $value),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setNormalizer('attr', static fn (Options $options, array $attr): array => $attr + ['inputmode' => 'decimal']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
