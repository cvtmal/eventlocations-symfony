<?php

declare(strict_types=1);

namespace App\Inquiries\Form;

use App\Venues\ReadModel\VenueDetail;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One place for the form of the page and of the POST. The action ends in `#anfrage` because
 * the browser keeps that fragment across the POST, so a re-render opens at the form rather
 * than at the top of a long page.
 */
final readonly class InquiryFormFactory
{
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
            'action' => $this->urls->generate('inquiry_submit', ['slug' => $venue->slug]).'#anfrage',
        ]);
    }
}
