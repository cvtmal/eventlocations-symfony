<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Cms\Form\VenueContactType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The contact card with the inquiry address required, in the wizard only: the `/konto` card and the CMS card keep the
 * field optional for an offline venue, and `ChangeVenueContactHandler` refuses to empty it on an online one (D60;
 * `PublishVenue` requires it). A second `add` replaces the parent's field and keeps its position.
 */
final class WizardContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('inquiryEmail', EmailType::class, [
            'label' => 'contact.field.inquiry_email',
            'help' => 'contact.help.inquiry_email',
            'constraints' => [new Assert\NotBlank(message: 'portal.onboarding.inquiry_email_required')],
        ]);
    }

    public function getParent(): string
    {
        return VenueContactType::class;
    }
}
