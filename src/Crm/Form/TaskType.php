<?php

declare(strict_types=1);

namespace App\Crm\Form;

use App\Crm\ValueObject\TaskDueDate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Aufgabe erfassen" (CRM-5): `owners` are the accounts a task can go to (`OwnerOptionsQuery::selectable()`).
 * `dueOn` stays a string, the value the composer sends, so one shape rule covers both.
 */
final class TaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'task.form.title', 'constraints' => FieldRules::of(PropertyConstraints::taskTitle()), 'attr' => ['data-counter-max' => PropertyConstraints::TASK_TITLE_MAX]])
            ->add('dueOn', DateType::class, [
                'label' => 'task.form.due_on',
                'widget' => 'single_text',
                'input' => 'string',
                'invalid_message' => 'crm.task.due_on',
                // An empty field is '' here, not null: `input: 'string'` transforms null to a string.
                'constraints' => [
                    new Assert\NotBlank(message: 'crm.task.due_on'),
                    new Assert\Regex(pattern: TaskDueDate::SHAPE, message: 'crm.task.due_on'),
                ],
            ])
            ->add('assignee', ChoiceType::class, [
                'label' => 'task.form.assignee',
                'placeholder' => false,
                'constraints' => [new Assert\NotNull(message: 'crm.task.assignee')],
                ...OwnerChoices::options($options['owners']),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TaskData::class, 'translation_domain' => 'crm']);
        $resolver->setRequired('owners');
        $resolver->setAllowedTypes('owners', 'array');
    }
}
