<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The document edit page (#25, CMS-7): the titles and the row token.
 */
final class DocumentTitleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('loadedAt', HiddenType::class)
            ->add('titles', LocaleTitlesType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DocumentTitleData::class, 'translation_domain' => 'cms']);
    }
}
