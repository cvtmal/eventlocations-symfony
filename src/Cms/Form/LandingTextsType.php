<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Translation\EnabledLocale;
use App\Shared\ValueObject\LandingPageEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One `LandingLocaleTextsType` per enabled locale under `locales[<locale>]`, as #24's Texte card does, plus
 * the hidden version token. Option `entry`: the page, whose defaults become the placeholders.
 */
final class LandingTextsType extends AbstractType
{
    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entry = $options['entry'];
        \assert($entry instanceof LandingPageEntry);

        $locales = $builder->create('locales', FormType::class, ['label' => false]);
        foreach ($this->locales->all() as $locale) {
            $locales->add($locale, LandingLocaleTextsType::class, [
                'label' => false,
                'locale' => $locale,
                'defaults' => ['intro' => $entry->defaultIntro, 'title' => $entry->defaultTitle, 'description' => $entry->defaultDescription],
            ]);
        }
        $builder->add($locales)->add('version', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LandingTextsData::class,
            'translation_domain' => 'cms',
        ]);
        $resolver->setRequired('entry');
        $resolver->setAllowedTypes('entry', LandingPageEntry::class);
    }
}
