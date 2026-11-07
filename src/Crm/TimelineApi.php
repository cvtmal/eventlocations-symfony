<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\Extension\TimelineActivityType;
use App\Admin\Extension\TimelineActivityTypeRegistry;
use App\Crm\ReadModel\TimelineEntry;
use App\Crm\ReadModel\TimelineQuery;
use App\Crm\ValueObject\RecordKind;
use App\Crm\ValueObject\TimelineCursor;
use App\Shared\Validator\CleanText;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The timeline's answers, one body per kind of record: `{"entries": [...], "next": token|null}` for a page and
 * `{"entry": {...}}` after a write, in `TimelineEntry::toArray()`'s shape. A page that holds a change of tier adds
 * `"tiers": {code: name}` (F12), because the island read the names for its first page only when that held one. A
 * cursor that does not parse is the first page, a type no module registers a 422 on `type`.
 */
final class TimelineApi
{
    public function __construct(
        private readonly TimelineQuery $timeline,
        private readonly TimelineActivityTypeRegistry $types,
        private readonly ValidatorInterface $validator,
        private readonly CrmApi $api,
        private readonly TierChoices $tiers,
    ) {
    }

    public function page(Request $request, RecordKind $kind, string $id, string $viewerId): JsonResponse
    {
        // `all()`, because `get()` answers an array with a 400.
        $query = $request->query->all();
        $type = '' === ($query['type'] ?? null) ? null : $query['type'] ?? null;
        $before = $query['before'] ?? null;

        $violations = $this->validator->startContext()->atPath('type')->validate($type, new Assert\Sequentially([
            new Assert\Type('string'),
            new CleanText(),
            new Assert\Choice(
                choices: array_map(static fn (TimelineActivityType $known): string => $known->code(), $this->types->visible()),
                message: 'crm.activity.type',
            ),
        ]))->getViolations();
        if (\count($violations) > 0) {
            return $this->api->invalid($violations);
        }
        \assert(null === $type || \is_string($type));

        if (!$this->timeline->recordExists($kind, $id)) {
            return $this->api->notFound();
        }

        $page = $this->timeline->page($kind, $id, $type, TimelineCursor::parse(\is_string($before) ? $before : null));

        return new JsonResponse(
            $page->toArray($viewerId, $this->types->composableCodes())
            + ($page->holdsTier() ? ['tiers' => $this->tiers->names()] : []),
        );
    }

    /**
     * The entry as it stands now, for the answer to a write.
     */
    public function stored(string $id, string $viewerId, int $status = Response::HTTP_OK): JsonResponse
    {
        $entry = $this->timeline->entry($id);

        return null === $entry ? $this->api->notFound() : $this->answer($entry, $viewerId, $status);
    }

    public function answer(TimelineEntry $entry, string $viewerId, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['entry' => $entry->toArray($viewerId, $this->types->composableCodes())], $status);
    }
}
