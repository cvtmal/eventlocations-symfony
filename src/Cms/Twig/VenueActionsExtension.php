<?php

declare(strict_types=1);

namespace App\Cms\Twig;

use App\Admin\Form\ConfirmActionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The header's publish, unpublish and delete forms (P20), built from the venue number alone, so
 * `_layout.html.twig` renders them on #25's tabs too, whose controllers know nothing of these actions.
 * Each is a `ConfirmActionType` named after its action: its own CSRF token id, and the name the
 * status controller binds.
 */
final readonly class VenueActionsExtension
{
    public const array ROUTES = [
        'publish' => 'admin_locations_publish',
        'unpublish' => 'admin_locations_unpublish',
        'delete' => 'admin_locations_delete',
    ];

    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsTwigFunction('cms_venue_action_form')]
    public function actionForm(string $action, int $number): FormView
    {
        $route = self::ROUTES[$action] ?? throw new \InvalidArgumentException(\sprintf('Unknown venue action "%s".', $action));

        return $this->forms->createNamed($action, ConfirmActionType::class, null, [
            'action' => $this->urls->generate($route, ['number' => $number]),
        ])->createView();
    }
}
