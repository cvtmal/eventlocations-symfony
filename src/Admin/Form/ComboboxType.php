<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A text field the theme turns into the `combobox` island; before the mount and without JS it stays a plain
 * input that submits. Options come from `url` (answers `{"options": [{"value", "label", "detail"}]}`) or `static_options`.
 *
 * Two modes. Select (default): the value differs from the label, `display_value` is the label of the current
 * value. Free text (`free_text: true`): value = label, what was typed is what is submitted, option or not.
 */
final class ComboboxType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'url' => null,
            'static_options' => [],
            'display_value' => null,
            'free_text' => false,
        ]);
        $resolver->setAllowedTypes('url', ['null', 'string']);
        $resolver->setAllowedTypes('static_options', 'array');
        $resolver->setAllowedTypes('display_value', ['null', 'string']);
        $resolver->setAllowedTypes('free_text', 'bool');

        $resolver->setNormalizer('display_value', static function (Options $options, ?string $value): ?string {
            if (null !== $value && true === $options['free_text']) {
                throw new InvalidOptionsException('A free-text combobox shows its value, so it takes no "display_value".');
            }

            return $value;
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['url'] = $options['url'];
        $view->vars['static_options'] = $options['static_options'];
        $view->vars['display_value'] = $options['display_value'] ?? '';
        $view->vars['free_text'] = $options['free_text'];
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'combobox';
    }
}
