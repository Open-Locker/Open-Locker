<?php

declare(strict_types=1);

namespace Tests\Support;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

trait AssertsJsonSchemas
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertMatchesAsyncApiSchema(array $payload, string $schemaPath): void
    {
        $schemasDirectory = $this->asyncApiSchemasDirectory();
        $absoluteSchemaPath = $schemasDirectory.DIRECTORY_SEPARATOR.$schemaPath;

        $this->assertFileExists($absoluteSchemaPath);

        $validator = new Validator;
        $validator->loader()->setResolver($this->schemaResolver());

        $validation = $validator->validate(
            $this->toJsonValue($payload),
            $this->fileUri($absoluteSchemaPath),
        );

        if ($validation->isValid()) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(sprintf(
            "Payload does not match AsyncAPI schema [%s]:\n%s",
            $schemaPath,
            $this->formatValidationError($validation->error()),
        ));
    }

    private function schemaResolver(): SchemaResolver
    {
        return (new SchemaResolver)
            ->registerProtocol('file', function (Uri $uri): object {
                $schemaUri = $this->fileUri((string) $uri->path());
                $schema = json_decode(
                    (string) file_get_contents((string) $uri->path()),
                    false,
                    flags: JSON_THROW_ON_ERROR,
                );

                $this->assertIsObject($schema);

                $schema->{'$id'} = $schemaUri;

                return $schema;
            });
    }

    private function asyncApiSchemasDirectory(): string
    {
        foreach ([
            base_path('../docs/asyncapi/schemas'),
            '/var/www/docs/asyncapi/schemas',
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        $this->fail('AsyncAPI schemas directory is not readable by backend tests.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toJsonValue(array $payload): object
    {
        $value = json_decode((string) json_encode($payload), false, flags: JSON_THROW_ON_ERROR);

        $this->assertIsObject($value);

        return $value;
    }

    private function formatValidationError(?ValidationError $error): string
    {
        if ($error === null) {
            return 'Unknown validation error.';
        }

        return implode("\n", $this->collectValidationErrors($error));
    }

    /**
     * @return list<string>
     */
    private function collectValidationErrors(ValidationError $error): array
    {
        $path = $this->formatJsonPath($error->data()->fullPath());
        $message = sprintf('- %s: %s', $path, $error->message());
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            return [$message];
        }

        return array_merge(
            [$message],
            ...array_map(fn (ValidationError $subError): array => $this->collectValidationErrors($subError), $subErrors),
        );
    }

    /**
     * @param  list<int|string>  $path
     */
    private function formatJsonPath(array $path): string
    {
        if ($path === []) {
            return '$';
        }

        return '$.'.implode('.', array_map(
            static fn (int|string $segment): string => is_int($segment) ? "[{$segment}]" : (string) $segment,
            $path,
        ));
    }

    private function fileUri(string $path): string
    {
        return 'file://'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
