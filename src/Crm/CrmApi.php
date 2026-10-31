<?php

declare(strict_types=1);

namespace App\Crm;

use App\Admin\ValueObject\ValidationErrors;
use App\Crm\Exception\CompanyNotFound;
use App\Crm\Exception\ContactEmailTaken;
use App\Crm\Exception\ContactNotFound;
use App\Crm\Exception\InvalidPropertyValue;
use App\Crm\Exception\StaleProperty;
use App\Crm\Form\PropertyEdit;
use App\Shared\Validator\CleanText;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The answers of the CRM's JSON endpoints (D11), the sibling of #25's `Cms\VenueMediaApi`, which
 * Crm may not import. Every error is `{"errors": {"<field>": ["message"]}}`: invalid input and the
 * handler's input rules 422 on their field, a stale field 409 on `""`, a missing record 404 on `""`.
 * `adminFetch` drops the body of a 409 and a 404, so the island shows its own labels for them.
 */
final class CrmApi
{
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
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
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
                $exception instanceof InvalidPropertyValue => $this->error('value', $exception->isRequired() ? 'api.required' : 'api.unknown_choice', Response::HTTP_UNPROCESSABLE_ENTITY),
                $exception instanceof CompanyNotFound, $exception instanceof ContactNotFound => $this->notFound(),
                default => throw $failure,
            };
        }

        throw $failure;
    }

    private function error(string $field, string $key, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [$field => [$this->translator->trans($key, [], 'crm')]]], $status);
    }
}
