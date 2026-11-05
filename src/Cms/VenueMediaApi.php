<?php

declare(strict_types=1);

namespace App\Cms;

use App\Media\Exception\UnreadableImage;
use App\Venues\Exception\StaleEdit;
use App\Venues\Exception\VenueNotFound;
use App\Venues\Exception\VenueRowNotFound;
use App\Venues\Exception\VenueRuleViolation;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The answers the photo and room endpoints share (#25, D21, #22's JSON shape `{"errors": {field: [...]}}`).
 * Fields are dotted paths (`names.de`, `seating.banquet.max`, `alts.de`, `file`), the shape of
 * `VenueRuleViolation::$field`, so the island maps a form error and a handler error the same way. `''` is
 * the whole request. `ValidationErrors::fromForm()` keys by the field's own name, which collides here
 * (`names.de` and `descriptions.de` are both `de`).
 */
final class VenueMediaApi
{
    /** More ids than any scope holds (positions are SMALLINT, a venue has a few dozen photos). */
    private const int MAX_IDS = 1000;

    /** Well above any list, room or alts body; json_decode() needs many times a body's size in memory. */
    public const int MAX_BODY_BYTES = 1_000_000;

    /**
     * The `{id}` of the id routes (`…/photos/{id}/alt`, `…/rooms/{id}` …) in the URLs the host pages give the
     * islands: the island replaces it with a row's id. It matches `Requirement::UUID` (version 7, variant 8),
     * so `path()` can build the URL, and no row ever has it (`EntityId` draws the random bits).
     */
    public const string ID_PLACEHOLDER = '00000000-0000-7000-8000-000000000000';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The decoded JSON body; malformed JSON, a scalar or a body above MAX_BODY_BYTES is an empty body, so every
     * field is missing and the answer is a 422 the island can read (as `MarkdownPreviewController`).
     *
     * @return array<mixed>
     */
    public static function payload(Request $request): array
    {
        $content = $request->getContent();
        $payload = \strlen($content) > self::MAX_BODY_BYTES ? null : json_decode($content, true);

        return \is_array($payload) ? $payload : [];
    }

    /**
     * A room id field: null or '' is the gallery (null), a UUID is a room, anything else false (a 422).
     */
    public static function roomId(mixed $value): string|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \is_string($value) && Uuid::isValid($value) ? $value : false;
    }

    /**
     * A reorder's id list: a JSON list of strings, else null (a 422). Whether the ids are exactly the
     * scope's is the handler's check (`Positions::inOrder()`, a 409).
     *
     * @return ?list<string>
     */
    public static function ids(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value) || \count($value) > self::MAX_IDS) {
            return null;
        }
        foreach ($value as $id) {
            if (!\is_string($id)) {
                return null;
            }
        }

        /* @var list<string> $value */
        return $value;
    }

    /**
     * The venue, or one of its photos, rooms, documents, is unknown (D21).
     */
    public function notFound(): JsonResponse
    {
        return self::errors(['' => [$this->translator->trans('error.not_found', [], 'cms')]], Response::HTTP_NOT_FOUND);
    }

    /**
     * A 422 on one field with a `validators` message.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function invalid(string $field, string $message, array $parameters = []): JsonResponse
    {
        return self::errors([$field => [$this->translator->trans($message, $parameters, 'validators')]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    public function formErrors(FormInterface $form): JsonResponse
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            \assert($error instanceof FormError);
            $path = [];
            for ($origin = $error->getOrigin(); null !== $origin && null !== $origin->getParent(); $origin = $origin->getParent()) {
                array_unshift($path, $origin->getName());
            }
            $errors[implode('.', $path)][] = $error->getMessage();
        }

        return self::errors($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Validator violations by dotted path (`alts[de]` → `alts.de`).
     */
    public function violations(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $path = trim(str_replace(['][', '[', ']'], '.', $violation->getPropertyPath()), '.');
            $errors[$path][] = (string) $violation->getMessage();
        }

        return self::errors($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The answer to a failed command: 404 for an unknown venue or row, 409 for a stale token or order
     * (PF2, D11), 422 for a business rule or an image gd cannot decode (D6). Anything else is a bug or an outage and is rethrown.
     *
     * @throws HandlerFailedException
     */
    public function failure(HandlerFailedException $failure): JsonResponse
    {
        if ([] !== $failure->getWrappedExceptions(VenueNotFound::class) || [] !== $failure->getWrappedExceptions(VenueRowNotFound::class)) {
            return $this->notFound();
        }
        if ([] !== $failure->getWrappedExceptions(StaleEdit::class)) {
            return self::errors(['' => [$this->translator->trans('error.stale', [], 'cms')]], Response::HTTP_CONFLICT);
        }
        if ([] !== $failure->getWrappedExceptions(UnreadableImage::class)) {
            return $this->invalid('file', 'cms.photo.unreadable');
        }

        $violation = VenueRuleViolation::unwrap($failure);

        return self::errors([$violation->field ?? '' => [$this->translator->trans($violation->translationKey(), $violation->parameters, 'cms')]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function errors(array $errors, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => $errors], $status);
    }
}
