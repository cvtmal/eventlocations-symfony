<?php

declare(strict_types=1);

namespace App\Admin\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

/**
 * A textarea the theme gives the tabs Schreiben and Vorschau; the preview is `admin_api_markdown_preview`.
 */
final class MarkdownType extends AbstractType
{
    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'markdown';
    }
}
