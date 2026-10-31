<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Shared\Translation\EnabledLocale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A document's title per enabled locale (`titles[<locale>]`, #25, CMS-7), as named children so no other
 * locale is accepted; the default locale's is required (the handler's rule too, D22).
 */
final class LocaleTitlesType extends AbstractType
{
    public const int TITLE_MAX = 200;

    public function __construct(
        private readonly EnabledLocale $locales,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($this->locales->all() as $locale) {
            $isDefault = $locale === $this->locales->default();
            $builder->add($locale, TextType::class, [
                'label' => 'documents.field.title',
                'required' => $isDefault,
                'constraints' => [
                    ...($isDefault ? [new Assert\NotBlank(message: 'cms.document.title.required')] : []),
                    new Assert\Length(max: self::TITLE_MAX, maxMessage: 'cms.venue.too_long'),
                ],
                'attr' => ['data-counter-max' => self::TITLE_MAX],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['label' => false, 'translation_domain' => 'cms']);
    }
}
