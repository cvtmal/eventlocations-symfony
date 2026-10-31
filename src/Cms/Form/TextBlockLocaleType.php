<?php

declare(strict_types=1);

namespace App\Cms\Form;

use App\Admin\Form\MarkdownType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

final class TextBlockLocaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', MarkdownType::class, [
            'label' => 'blocks.editor.field.body',
            'help' => 'blocks.editor.help.body',
            'required' => false,
            'constraints' => [new Length(max: $options['max_length'], maxMessage: 'cms.block.too_long', normalizer: 'trim')],
            'attr' => ['rows' => 6, 'data-counter-max' => $options['max_length']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TextBlockLocaleData::class,
            'translation_domain' => 'cms',
        ]);
        $resolver->setRequired('max_length');
        $resolver->setAllowedTypes('max_length', 'int');
    }
}
