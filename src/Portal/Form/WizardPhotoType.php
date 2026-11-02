<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Cms\Form\SafeClientName;
use App\Cms\VenuePhotoUpload;
use App\Venues\Command\AddVenuePhoto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The photo step's form for a browser without JavaScript (#45). No data class: `rule()` is built at runtime, so it
 * cannot be an attribute.
 */
final class WizardPhotoType extends AbstractType
{
    public function __construct(private readonly VenuePhotoUpload $upload)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, [
            'label' => 'onboarding.photos.fallback_label',
            'constraints' => [new Assert\NotNull(message: 'portal.onboarding.photo_required'), $this->upload->rule()],
            'attr' => ['accept' => implode(',', array_keys(AddVenuePhoto::EXTENSIONS))],
        ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (\is_array($data) && ($data['file'] ?? null) instanceof UploadedFile) {
                $data['file'] = SafeClientName::of($data['file']);
                $event->setData($data);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'portal']);
    }
}
