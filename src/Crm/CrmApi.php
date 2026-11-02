<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\ValueObject\ValidationErrors;
use App\Crm\Exception\ActivityNotFound;
use App\Crm\Exception\ActivityNotYours;
use App\Crm\Exception\ActivityReadOnly;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\CompanyStageDerived;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\ContactOfAnotherCompany;
use App\Crm\Exception\ContractEndsBeforeStart;
use App\Crm\Exception\DealNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\LostReasonRequired;
use App\Crm\Exception\NoEmailAddress;
use App\Crm\Exception\StaleProperty;
use App\Crm\Exception\UnknownDealStage;
use App\Crm\Exception\VenueNotLinked;
use App\Crm\Form\ActivityEdit;
use App\Crm\Form\EmailDraftRequest;
use App\Crm\Form\NewActivity;
use App\Crm\Form\NewEmail;
use App\Crm\Form\NewTask;
use App\Crm\Form\PropertyConstraints;
use App\Crm\Form\PropertyEdit;
use App\Crm\Form\StageMove;
use App\Crm\ValueObject\ActivityDate;
use App\Crm\ValueObject\LostReason;
use App\Crm\ValueObject\PropertyValue;
use App\Crm\ValueObject\RecordKind;
use App\Crm\ValueObject\TaskDueDate;
use App\Shared\Validator\CleanText;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ContextualValidatorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The answers of the CRM's JSON endpoints (D11), the sibling of #25's `Cms\VenueMediaApi`, which
 * Crm may not import. Every error is `{"errors": {"<field>": ["message"]}}`: invalid input and the
 * handler's input rules 422 on their field, a stale field 409 on `""`, a missing record 404 on `""`,
 * somebody else's or a read-only timeline entry 403 on `""`.
 * `adminFetch` drops the body of a 409 and a 404, so the island shows its own labels for them.
 */
final class CrmApi
{
    /** `InvalidPropertyValue::$field` of `CreateTask` to the composer's input. */
    private const array TASK_FIELDS = ['title' => 'title', 'owner' => 'assigneeId'];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Reads `{"field", "value", "expected"}`. Malformed JSON, invalid UTF-8 included, is a missing
     * field: `toArray()` would answer an HTML 400 the island cannot read (`MarkdownPreviewController`).
     * A missing `value` or `expected` is null. A `\u0000` decodes and is refused by CleanText.
     *
     * @param list<string> $fields the field names the record allows
     */
    public function propertyEdit(Request $request, array $fields): PropertyEdit|JsonResponse
    {
        $payload = self::payload($request);
        $field = $payload['field'] ?? null;
        $value = $payload['value'] ?? null;
        $expected = $payload['expected'] ?? null;
        $text = new Assert\Sequentially([new Assert\Type('string'), new CleanText()]);

        $violations = $this->validator->startContext()
            ->atPath('field')->validate($field, new Assert\Sequentially([
                new Assert\NotNull(message: 'crm.property.unknown_field'),
                new Assert\Type('string'),
                new Assert\Choice(choices: $fields, message: 'crm.property.unknown_field'),
            ]))
            ->atPath('value')->validate($value, $text)
            ->atPath('expected')->validate($expected, $text)
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($field) && (null === $value || \is_string($value)) && (null === $expected || \is_string($expected)));

        return new PropertyEdit($field, $value, $expected);
    }

    /**
     * Reads `{"kind", "recordId", "type", "body", "occurredAt"}`. A record id that names nothing is the handler's
     * to refuse, with a 404 like every other missing record.
     *
     * @param list<string> $types the kinds this user may write
     */
    public function newActivity(Request $request, array $types): NewActivity|JsonResponse
    {
        $payload = self::payload($request);
        $kind = $payload['kind'] ?? null;
        $recordId = $payload['recordId'] ?? null;
        $type = $payload['type'] ?? null;
        $body = self::canonicalText($payload, 'body');
        $occurredAt = ActivityDate::parse($payload['occurredAt'] ?? null);

        $violations = $this->recordFields($this->activityFields($body, $occurredAt), $kind, $recordId, RecordKind::cases())
            ->atPath('type')->validate($type, self::choice($types, 'crm.activity.type'))
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($kind) && \is_string($recordId) && \is_string($type) && \is_string($body) && null !== $occurredAt);

        return new NewActivity(RecordKind::from($kind), $recordId, $type, $body, $occurredAt);
    }

    /**
     * Reads `{"body", "occurredAt"}`, what the composer's edit mode can change.
     */
    public function activityEdit(Request $request): ActivityEdit|JsonResponse
    {
        $payload = self::payload($request);
        $body = self::canonicalText($payload, 'body');
        $occurredAt = ActivityDate::parse($payload['occurredAt'] ?? null);

        $violations = $this->activityFields($body, $occurredAt)->getViolations();
        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($body) && null !== $occurredAt);

        return new ActivityEdit($body, $occurredAt);
    }

    /**
     * Reads `{"kind", "recordId", "contactId", "cc", "subject", "body"}`, the composer's send form. A deal has no mail
     * step, so its `kind` is refused here; a missing record, and a contact that does not fit, are the handler's.
     */
    public function newEmail(Request $request): NewEmail|JsonResponse
    {
        $payload = self::payload($request);
        $kind = $payload['kind'] ?? null;
        $recordId = $payload['recordId'] ?? null;
        $contactId = self::canonicalText($payload, 'contactId');
        $cc = $payload['cc'] ?? null;
        $cc = \is_string($cc) ? PropertyValue::canonical($cc, email: true) : $cc;
        $subject = self::canonicalText($payload, 'subject');
        $body = self::canonicalText($payload, 'body');

        $violations = $this->recordFields($this->validator->startContext(), $kind, $recordId, [RecordKind::Company, RecordKind::Contact])
            ->atPath('contactId')->validate($contactId, new Assert\Sequentially(PropertyConstraints::emailContact()))
            ->atPath('cc')->validate($cc, new Assert\Sequentially(PropertyConstraints::emailCc()))
            ->atPath('subject')->validate($subject, new Assert\Sequentially(PropertyConstraints::emailSubject()))
            ->atPath('body')->validate($body, new Assert\Sequentially(PropertyConstraints::activityBody()))
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($kind) && \is_string($recordId) && (null === $contactId || \is_string($contactId)) && (null === $cc || \is_string($cc)) && \is_string($subject) && \is_string($body));

        return new NewEmail(RecordKind::from($kind), $recordId, $contactId, $cc, $subject, $body);
    }

    /**
     * Reads `kind`, `recordId`, `template` and `contact` from the query. `EmailMergeQuery` has no guard of its own, so
     * the ids stop here, a blank one too, or Postgres answers 22P02 and the island a 500. An empty `contact` is none.
     */
    public function emailDraft(Request $request): EmailDraftRequest|JsonResponse
    {
        // `all()`, because `get()` answers an array with a 400.
        $query = $request->query->all();
        $kind = $query['kind'] ?? null;
        $recordId = $query['recordId'] ?? null;
        $templateId = $query['template'] ?? null;
        $contactId = '' === ($query['contact'] ?? null) ? null : $query['contact'] ?? null;

        $violations = $this->validator->startContext()
            ->atPath('kind')->validate($kind, self::choice([RecordKind::Company->value, RecordKind::Contact->value], 'crm.activity.kind'))
            ->atPath('recordId')->validate($recordId, new Assert\Sequentially([new Assert\NotBlank(message: 'crm.activity.record'), ...PropertyConstraints::emailContact()]))
            ->atPath('template')->validate($templateId, new Assert\Sequentially([new Assert\NotBlank(message: 'crm.property.choice'), ...PropertyConstraints::emailContact()]))
            ->atPath('contact')->validate($contactId, new Assert\Sequentially(PropertyConstraints::emailContact()))
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($kind) && \is_string($recordId) && \is_string($templateId) && (null === $contactId || \is_string($contactId)));

        return new EmailDraftRequest(RecordKind::from($kind), $recordId, $templateId, $contactId);
    }

    /**
     * Reads `{"kind", "recordId", "title", "dueOn", "assigneeId"}`, the composer's Aufgabe tab. Errors are keyed
     * `title|dueOn|assigneeId` under the inputs; a missing record is the handler's 404, as for an activity.
     */
    public function newTask(Request $request): NewTask|JsonResponse
    {
        $payload = self::payload($request);
        $kind = $payload['kind'] ?? null;
        $recordId = $payload['recordId'] ?? null;
        $title = self::canonicalText($payload, 'title');
        $dueOn = TaskDueDate::parse($payload['dueOn'] ?? null);
        $assigneeId = $payload['assigneeId'] ?? null;

        $violations = $this->recordFields($this->validator->startContext(), $kind, $recordId, RecordKind::cases())
            ->atPath('title')->validate($title, new Assert\Sequentially(PropertyConstraints::taskTitle()))
            ->atPath('dueOn')->validate($dueOn, new Assert\NotNull(message: 'crm.task.due_on'))
            ->atPath('assigneeId')->validate($assigneeId, new Assert\Sequentially(PropertyConstraints::taskAssignee()))
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($kind) && \is_string($recordId) && \is_string($title) && null !== $dueOn && \is_string($assigneeId));

        return new NewTask(RecordKind::from($kind), $recordId, $title, $dueOn, $assigneeId);
    }

    /**
     * Reads `{"stage", "expected", "lostReason"}`. `expected` is the stage the card showed (D17-b), a stage that exists
     * like the target; whether a lost deal has its reason is the handler's rule.
     *
     * @param list<string> $stages the codes of `crm_deal_stages`
     */
    public function stageMove(Request $request, array $stages): StageMove|JsonResponse
    {
        $payload = self::payload($request);
        $stage = $payload['stage'] ?? null;
        $expected = $payload['expected'] ?? null;
        $lostReason = $payload['lostReason'] ?? null;

        $violations = $this->validator->startContext()
            ->atPath('stage')->validate($stage, self::choice($stages, 'crm.deal.stage'))
            ->atPath('expected')->validate($expected, self::choice($stages, 'crm.deal.stage'))
            ->atPath('lostReason')->validate($lostReason, new Assert\Sequentially([
                new Assert\Type('string'),
                new CleanText(),
                new Assert\Choice(choices: array_column(LostReason::cases(), 'value'), message: 'crm.deal.lost_reason'),
            ]))
            ->getViolations();

        if (\count($violations) > 0) {
            return $this->invalid($violations);
        }

        \assert(\is_string($stage) && \is_string($expected) && (null === $lostReason || \is_string($lostReason)));

        return new StageMove($stage, $expected, null === $lostReason ? null : LostReason::from($lostReason));
    }

    public function invalid(ConstraintViolationListInterface $violations): JsonResponse
    {
        return new JsonResponse(ValidationErrors::fromViolations($violations)->toArray(), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function notFound(): JsonResponse
    {
        return $this->error('', 'api.not_found', Response::HTTP_NOT_FOUND);
    }

    /**
     * Maps what a CRM handler threw to its answer; anything else is not the island's to show and
     * bubbles to a 500.
     *
     * @throws HandlerFailedException when the handler failed for another reason
     */
    public function failure(HandlerFailedException $failure): JsonResponse
    {
        foreach ($failure->getWrappedExceptions() as $exception) {
            return match (true) {
                $exception instanceof StaleProperty => $this->error('', 'api.stale', Response::HTTP_CONFLICT),
                $exception instanceof ContactEmailTaken => $this->error('value', 'api.email_taken', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof CompanyStageDerived => $this->error('value', 'api.stage_derived', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof InvalidPropertyValue => $this->error('value', $exception->isRequired() ? 'api.required' : 'api.unknown_choice', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof ContractEndsBeforeStart => $this->error('value', 'api.contract_ends_before_start', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof VenueNotLinked => $this->error('value', 'api.venue_not_linked', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof ContactOfAnotherCompany => $this->error('value', 'api.contact_of_another_company', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof UnknownDealStage => $this->error('stage', 'api.unknown_choice', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof LostReasonRequired => $this->error('lostReason', 'api.lost_reason_required', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof NoEmailAddress => $this->error('contactId', 'api.no_email_address', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof ActivityNotYours => $this->error('', 'api.not_yours', Response::HTTP_FORBIDDEN),
                $exception instanceof ActivityReadOnly => $this->error('', 'api.read_only', Response::HTTP_FORBIDDEN),
                $exception instanceof CompanyNotFound, $exception instanceof ContactNotFound, $exception instanceof ActivityNotFound, $exception instanceof DealNotFound => $this->notFound(),
                default => throw $failure,
            };
        }

        throw $failure;
    }

    /**
     * `failure()` for `CreateTask`: the handler's refusals land on the composer's inputs, not on `value`.
     *
     * @throws HandlerFailedException when the handler failed for another reason
     */
    public function taskFailure(HandlerFailedException $failure): JsonResponse
    {
        $invalid = array_values($failure->getWrappedExceptions(InvalidPropertyValue::class))[0] ?? null;
        if (null === $invalid) {
            return $this->failure($failure);
        }

        return $this->error(self::TASK_FIELDS[$invalid->field] ?? throw $failure, $invalid->isRequired() ? 'api.required' : 'api.unknown_choice', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return array<mixed>
     */
    private static function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return \is_array($payload) ? $payload : [];
    }

    /**
     * @param array<mixed> $payload
     */
    private static function canonicalText(array $payload, string $key): mixed
    {
        $text = $payload[$key] ?? null;

        return \is_string($text) ? PropertyValue::canonical($text) : $text;
    }

    /**
     * @param list<string> $choices
     */
    private static function choice(array $choices, string $message): Assert\Sequentially
    {
        return new Assert\Sequentially([
            new Assert\NotNull(message: $message),
            new Assert\Type('string'),
            new CleanText(),
            new Assert\Choice(choices: $choices, message: $message),
        ]);
    }

    /**
     * @param list<RecordKind> $kinds the kinds this write can hang on
     */
    private function recordFields(ContextualValidatorInterface $fields, mixed $kind, mixed $recordId, array $kinds): ContextualValidatorInterface
    {
        return $fields
            ->atPath('kind')->validate($kind, self::choice(array_column($kinds, 'value'), 'crm.activity.kind'))
            ->atPath('recordId')->validate($recordId, new Assert\Sequentially([
                new Assert\NotNull(message: 'crm.activity.record'),
                new Assert\Type('string'),
                new CleanText(),
            ]));
    }

    private function activityFields(mixed $body, ?\DateTimeImmutable $occurredAt): ContextualValidatorInterface
    {
        return $this->validator->startContext()
            ->atPath('body')->validate($body, new Assert\Sequentially(PropertyConstraints::activityBody()))
            ->atPath('occurredAt')->validate($occurredAt, new Assert\NotNull(message: 'crm.activity.occurred_at'));
    }

    private function error(string $field, string $key, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [$field => [$this->translator->trans($key, [], 'crm')]]], $status);
    }
}
