<?php

declare(strict_types=1);

namespace App\Venues;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validates a parsed venue.yaml against docs/content-format.schema.json (draft-07, opis).
 *
 * Opis reports `additionalProperties: false` against every property of an object once any
 * sibling property failed; such messages are rebuilt from the object's real extra
 * properties and dropped when there are none, so the owner sees only real problems.
 */
final class ContentSchema
{
    public const int MAX_ERRORS = 25;

    private ?object $schema = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
    }

    /**
     * @return list<string> "<json-pointer>: <message>", at most MAX_ERRORS; empty when valid
     */
    public function validate(\stdClass $data): array
    {
        $result = (new Validator(max_errors: self::MAX_ERRORS, stop_at_first_error: false))->validate($data, $this->schema());
        $error = $result->error();
        if (null === $error) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter())->format($error, true, self::message(...)) as $pointer => $list) {
            foreach ($list as $message) {
                if (null !== $message) {
                    $messages[] = \sprintf('%s: %s', $pointer, $message);
                }
            }
        }

        return \array_slice(array_values(array_unique($messages)), 0, self::MAX_ERRORS);
    }

    public function schemaFile(): string
    {
        return $this->projectDirectory.'/'.ContentFormat::SCHEMA_FILE;
    }

    private function schema(): object
    {
        if (null === $this->schema) {
            $schema = json_decode((string) file_get_contents($this->schemaFile()), flags: \JSON_THROW_ON_ERROR);
            \assert($schema instanceof \stdClass);
            $this->schema = $schema;
        }

        return $this->schema;
    }

    private static function message(ValidationError $error, ?string $message = null): ?string
    {
        $formatted = (new ErrorFormatter())->formatErrorMessage($error, $message);
        $schema = $error->schema()->info()->data();
        $data = $error->data()->value();

        if ('additionalProperties' === $error->keyword() && $schema instanceof \stdClass && false === ($schema->additionalProperties ?? null) && $data instanceof \stdClass) {
            $declared = $schema->properties ?? new \stdClass();
            \assert($declared instanceof \stdClass);
            $extra = array_values(array_diff(array_keys(get_object_vars($data)), array_keys(get_object_vars($declared))));

            return [] === $extra ? null : 'Unknown properties: '.implode(', ', $extra);
        }

        if ('enum' === $error->keyword() && \is_scalar($data)) {
            return \sprintf('"%s" is not an allowed value', $data);
        }

        return $formatted;
    }
}
