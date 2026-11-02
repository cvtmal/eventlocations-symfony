<?php

declare(strict_types=1);

namespace App\Portal\Form;

use App\Portal\Security\PortalArea;
use App\Shared\Validator\CleanText;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;

/**
 * `CleanText` on every text field under /konto, the portal's copy of AdminTextConstraintExtension: a NUL or
 * invalid UTF-8 is a 422 with a field error instead of a truncated value or a 500.
 */
final class PortalTextConstraintExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly PortalArea $portalArea,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [TextType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->addNormalizer('constraints', function (Options $options, mixed $constraints): mixed {
            $request = $this->requests->getMainRequest();
            if (null === $request || !$this->portalArea->contains($request)) {
                return $constraints;
            }

            $constraints = $constraints instanceof Constraint ? [$constraints] : (array) $constraints;
            $constraints[] = new CleanText();

            return $constraints;
        });
    }
}
