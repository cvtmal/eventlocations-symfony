<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Admin\Form\MarkdownType;
use App\Crm\ValueObject\MergeFields;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A mail template (CRM-10). The merge fields reach the help as a parameter: in a `+intl-icu` catalogue
 * `{contact.first_name}` would be read as an ICU argument.
 */
final class EmailTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'email_template.form.name', 'constraints' => FieldRules::of(PropertyConstraints::emailTemplateName())])
            ->add('subject', TextType::class, ['label' => 'email_template.form.subject', 'constraints' => FieldRules::of(PropertyConstraints::emailSubject()), 'attr' => ['data-counter-max' => PropertyConstraints::EMAIL_SUBJECT_MAX]])
            ->add('bodyMarkdown', MarkdownType::class, [
                'label' => 'email_template.form.body',
                'constraints' => FieldRules::of(PropertyConstraints::activityBody()),
                'help' => 'email_template.form.fields_help',
                'help_translation_parameters' => ['fields' => implode(', ', MergeFields::TOKENS)],
                'attr' => ['data-counter-max' => PropertyConstraints::NOTES_MAX],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EmailTemplateData::class, 'translation_domain' => 'crm']);
    }
}
