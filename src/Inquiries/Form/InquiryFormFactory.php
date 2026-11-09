<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Venues\ReadModel\VenueDetail;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One place for the form of the page and of the POST. The action ends in a fragment because
 * the browser keeps it across the POST, so a re-render opens at the form rather than at the
 * top of a long page. The fragment is the re-render's error summary or G7 notice: a fragment
 * target that cannot take the focus makes the browser drop it on <body> after load, a
 * focusable one keeps it, so a screen reader reads the summary (F16, like N5's confirmation).
 */
final readonly class InquiryFormFactory
{
    public const NOTICE_ANCHOR = 'anfrage-hinweis';

    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(VenueDetail $venue): FormInterface
    {
        $data = new InquiryData();
        $data->message = $this->translator->trans('form.message_default', ['name' => $venue->name], 'inquiries');

        return $this->forms->create(InquiryType::class, $data, [
            'action' => $this->urls->generate('inquiry_submit', ['slug' => $venue->slug]).'#'.self::NOTICE_ANCHOR,
        ]);
    }
}
