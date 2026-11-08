<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Cms\ValueObject\Francs;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Francs as text, Rappen in the model (F12): `Francs` reads the string, so no float ever holds an amount, and
 * `MoneyType` goes through one. The block prefix `money` keeps the admin theme's "CHF" addon. A text that is no amount
 * is the field's `invalid_message`, with `{{ value }}` emptied because the ICU formatter cannot take a submitted value
 * that is not valid UTF-8 (cross-cycle note #24 → #25).
 */
final class FrancsTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?int $rappen): ?string => null === $rappen ? null : Francs::format($rappen),
            static function (?string $text): ?int {
                if (null === $text || '' === trim($text)) {
                    return null;
                }
                try {
                    return Francs::parse(trim($text));
                } catch (\InvalidArgumentException $failure) {
                    throw new TransformationFailedException('Not an amount in francs.', previous: $failure);
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'invalid_message' => 'cms.tier.price.invalid',
            'invalid_message_parameters' => ['{{ value }}' => ''],
        ]);
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
