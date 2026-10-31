<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Admin\Form\MarkdownType;
use App\Cms\ValueObject\LandingKey;
use App\Cms\ValueObject\VerbatimText;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The fields of one locale. The placeholders are what the page shows without a text (`defaults`), and the
 * title and description carry the hooks of `serp-preview.js`.
 */
final class LandingLocaleTextsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{intro?: string, title?: string, description?: string} $defaults */
        $defaults = $options['defaults'];
        $placeholder = static fn (string $field): array => '' === ($defaults[$field] ?? '') ? [] : ['placeholder' => new VerbatimText($defaults[$field])];
        $preview = static fn (string $field): array => ['data-serp-source' => $field, 'data-serp-locale' => $options['locale']];

        $builder
            ->add('intro', MarkdownType::class, [
                'label' => 'landing.editor.field.intro',
                'help' => 'landing.editor.help.intro',
                'required' => false,
                'attr' => ['rows' => 6, 'data-counter-max' => LandingKey::INTRO_MAX_LENGTH] + $placeholder('intro'),
            ])
            ->add('title', TextType::class, [
                'label' => 'landing.editor.field.title',
                'help' => 'landing.editor.help.title',
                'required' => false,
                'attr' => ['data-counter-max' => LandingKey::TITLE_MAX_LENGTH] + $placeholder('title') + $preview('title'),
            ])
            ->add('description', TextareaType::class, [
                'label' => 'landing.editor.field.description',
                'help' => 'landing.editor.help.description',
                'required' => false,
                'attr' => ['rows' => 3, 'data-counter-max' => LandingKey::DESCRIPTION_MAX_LENGTH] + $placeholder('description') + $preview('description'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LandingLocaleTextsData::class,
            'translation_domain' => 'cms',
            'defaults' => [],
        ]);
        $resolver->setRequired('locale');
        $resolver->setAllowedTypes('locale', 'string');
        $resolver->setAllowedTypes('defaults', 'array');
    }
}
