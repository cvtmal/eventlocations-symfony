<?php

declare(strict_types=1);

namespace App\Cms\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The documents page's upload card (#25, CMS-7): the PDF and its titles. `file-limit.js` refuses a file
 * above the limit in the browser, so it keeps the typed titles; the data attributes give it the limit.
 */
final class DocumentUploadType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'documents.field.file',
                'help' => 'documents.help.file',
                'attr' => [
                    'accept' => 'application/pdf',
                    'data-max-bytes' => DocumentUploadData::maxBytes(),
                    'data-max-message' => $this->translator->trans('cms.document.file.too_large', [], 'validators'),
                ],
            ])
            ->add('titles', LocaleTitlesType::class);

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
        $resolver->setDefaults(['data_class' => DocumentUploadData::class, 'translation_domain' => 'cms']);
    }
}
