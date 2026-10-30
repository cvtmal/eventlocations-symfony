<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Security\AdminArea;
use App\Shared\Validator\CleanText;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraint;

/**
 * Adds `CleanText` to every text field of the back office, so a NUL or invalid UTF-8 is a 422 with a field
 * error instead of a truncated value or a 500 (R2-m1). Extending `TextType` reaches every type whose parent
 * it is (`EmailType`, `UrlType`, `TextareaType`, `PasswordType`, `SearchType`, `TelType`, `ComboboxType`,
 * `MarkdownType` …), not `HiddenType`, the number types, choices or dates, which do not take free text.
 *
 * It asks for the main request at resolve time: the public inquiry form and the console stay as they are,
 * and a form built without a request (a handler test) gets nothing.
 */
final class AdminTextConstraintExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly AdminArea $adminArea,
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
            if (null === $request || !$this->adminArea->contains($request)) {
                return $constraints;
            }

            $constraints = $constraints instanceof Constraint ? [$constraints] : (array) $constraints;
            $constraints[] = new CleanText();

            return $constraints;
        });
    }
}
