<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Portal\ClaimPhotoImport;
use App\Venues\ReadModel\PhotoCandidateRow;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * `candidates` (list<PhotoCandidateRow>) are the only choices, so a crafted id is a form error, not a write; `max`
 * (default `ClaimPhotoImport::MAX_PICKS`) caps the ticks at what the plan leaves free (F12).
 */
final class ClaimPhotosType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<PhotoCandidateRow> $candidates */
        $candidates = $options['candidates'];
        $builder
            ->add('photos', ChoiceType::class, [
                'label' => 'claim.photos.label',
                'choices' => array_combine(
                    array_map(static fn (PhotoCandidateRow $row): string => $row->id, $candidates),
                    array_map(static fn (PhotoCandidateRow $row): string => $row->id, $candidates),
                ),
                'choice_label' => static fn (string $id): string => $id,
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'constraints' => [new Assert\Count(max: $options['max'], maxMessage: 'portal.claim.too_many_photos')],
            ])
            ->add('declaration', CheckboxType::class, ['label' => 'rights.declaration_label', 'required' => false])
            ->add('derivatives', CheckboxType::class, ['label' => 'rights.derivatives', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClaimPhotosData::class, 'translation_domain' => 'portal']);
        $resolver->setRequired('candidates');
        $resolver->setAllowedTypes('candidates', 'array');
        $resolver->setDefault('max', ClaimPhotoImport::MAX_PICKS);
        $resolver->setAllowedTypes('max', 'int');
    }
}
