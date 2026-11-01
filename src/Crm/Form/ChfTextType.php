<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\Chf;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Francs as text, Rappen in the model (D6): `Chf` reads the string, so no float ever holds an amount. `MoneyType` goes
 * through one, and the `de` locale would group with a dot. The block prefix keeps the theme's `CHF` addon. A text that
 * is no amount fails as the field's `invalid_message`.
 */
final class ChfTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $rappen): ?string => null === $rappen ? null : Chf::format($rappen),
            static function (?string $text): ?int {
                if (null === $text || '' === trim($text)) {
                    return null;
                }
                try {
                    return Chf::parse(trim($text));
                } catch (\InvalidArgumentException $failure) {
                    throw new TransformationFailedException('Not an amount in francs.', previous: $failure);
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['invalid_message' => 'crm.property.amount']);
        $resolver->setNormalizer('attr', static fn (Options $options, array $attr): array => $attr + ['inputmode' => 'decimal']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'money';
    }
}
