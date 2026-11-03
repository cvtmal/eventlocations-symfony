<?php

declare(strict_types=1);

namespace App\Portal\Extension;

use App\Admin\Extension\RecordPanel;
use App\Cms\ValueObject\VenueNumber;
use App\Portal\Form\PortalManagerType;
use App\Portal\ReadModel\VenueManagersQuery;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * The managers of a location on its venue page (#43). The record id of a venue is its number; anything else
 * (`ExtensionPointsTest` probes with a UUID) gives an empty context, and the template then renders nothing.
 */
final readonly class VenueManagersPanel implements RecordPanel
{
    public function __construct(
        private VenueManagersQuery $managers,
        private FormFactoryInterface $forms,
    ) {
    }

    public function recordType(): string
    {
        return 'venue';
    }

    public function template(): string
    {
        return 'admin/portal/_venue_managers.html.twig';
    }

    public function context(string $recordId): array
    {
        if (1 !== preg_match('/^'.VenueNumber::REQUIREMENT.'$/D', $recordId)) {
            return [];
        }

        return [
            'portal_number' => (int) $recordId,
            'portal_managers' => $this->managers->forVenue((int) $recordId),
            'portal_add_form' => $this->forms->create(PortalManagerType::class)->createView(),
        ];
    }

    public function priority(): int
    {
        return 50;
    }

    public function requiredRole(): string
    {
        return 'ROLE_EDITOR';
    }
}
