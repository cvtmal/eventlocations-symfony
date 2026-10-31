<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Extension\ContentBlockDefinition;
use App\Shared\Translation\EnabledLocale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One `TextBlockLocaleType` per enabled locale under `locales[<locale>]`, plus the hidden version token.
 * Option `definition`: the block, whose `maxLength()` is every body's limit.
 */
final class TextBlockType extends AbstractType
{
    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $definition = $options['definition'];
        \assert($definition instanceof ContentBlockDefinition);

        $locales = $builder->create('locales', FormType::class, ['label' => false]);
        foreach ($this->locales->all() as $locale) {
            $locales->add($locale, TextBlockLocaleType::class, ['label' => false, 'max_length' => $definition->maxLength()]);
        }
        $builder->add($locales)->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TextBlockData::class,
            'translation_domain' => 'cms',
        ]);
        $resolver->setRequired('definition');
        $resolver->setAllowedTypes('definition', ContentBlockDefinition::class);
    }
}
