<?php

declare(strict_types=1);

namespace App\Inquiries\Twig;

use App\Inquiries\Form\InquiryFormFactory;
use App\Venues\ReadModel\VenueDetail;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UriSigner;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * `{{ inquiry_form(venue, form = null, undelivered = false) }}` fills #8's `{% block inquiry %}`. It adds no query and
 * no session, and renders the same for every visitor, so the detail page stays cacheable (S10):
 *
 * - nothing for a venue without an inquiry address (the detail page then renders no section, F16);
 * - the "stored, we deliver it" state when the POST passes `undelivered` (G7, D56): the venue mail did not go out;
 * - the confirmation on a signed `?anfrage=gesendet` (an unsigned or tampered flag gets the form);
 * - else the form: the bound one the POST passes in, or a fresh one.
 *
 * The flag is read from the main request because the function reads no route: it works in
 * #24's preview too.
 */
final class InquiryFormExtension
{
    private const FLAG = 'anfrage';
    private const FLAG_SENT = 'gesendet';

    /**
     * The redirect's fragment and the id of the confirmation's heading (F16 N5). A fragment whose target cannot take
     * the focus makes the browser clear it after load; a focusable target gets it, so a screen reader reads it.
     */
    public const CONFIRMATION_ANCHOR = 'anfrage-gesendet';

    public function __construct(
        private readonly InquiryFormFactory $forms,
        private readonly RequestStack $requests,
        private readonly UriSigner $signer,
    ) {
    }

    #[AsTwigFunction('inquiry_form', isSafe: ['html'])]
    public function inquiryForm(Environment $twig, VenueDetail $venue, ?FormView $form = null, bool $undelivered = false): string
    {
        if (null === $venue->inquiryEmail) {
            return '';
        }

        if ($undelivered) {
            return $twig->render('inquiries/_undelivered.html.twig', ['venue' => $venue, 'anchor' => InquiryFormFactory::NOTICE_ANCHOR]);
        }

        if ($this->isConfirmation()) {
            return $twig->render('inquiries/_confirmation.html.twig', ['venue' => $venue, 'anchor' => self::CONFIRMATION_ANCHOR]);
        }

        return $twig->render('inquiries/_form.html.twig', [
            'venue' => $venue,
            'form' => $form ?? $this->forms->create($venue)->createView(),
            'anchor' => InquiryFormFactory::NOTICE_ANCHOR,
        ]);
    }

    /**
     * The query parameters of the confirmation link, for the controller that signs it.
     *
     * @return array{anfrage: string}
     */
    public static function confirmationQuery(): array
    {
        return [self::FLAG => self::FLAG_SENT];
    }

    private function isConfirmation(): bool
    {
        $request = $this->requests->getMainRequest();
        if (null === $request || self::FLAG_SENT !== ($request->query->all()[self::FLAG] ?? null)) {
            return false;
        }

        return $this->signer->checkRequest($request);
    }
}
