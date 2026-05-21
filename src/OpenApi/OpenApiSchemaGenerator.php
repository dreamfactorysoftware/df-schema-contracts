<?php

namespace DreamFactory\Core\SchemaContracts\OpenApi;

use DreamFactory\Core\SchemaContracts\Canonical\FieldSchema;

/**
 * Generates OpenAPI 3.0 Schema Objects from canonical Table JSON.
 *
 * This is the Phase 2 deliverable: turn a connector-neutral canonical table
 * into the JSON-Schema fragment an OpenAPI `components/schemas` entry needs,
 * without consulting any connector code. Paths/operations remain DF's job;
 * this only produces the record-shape schema.
 *
 * Targets OpenAPI 3.0 conventions (`nullable: true`, `readOnly: true`) for
 * the widest tool compatibility. A 3.1 variant (type arrays with "null")
 * can be added later behind a flag if needed.
 *
 * Input is array-decoded canonical JSON (the same shape stored in snapshots),
 * not DTOs — so the generator works identically on a live describe and on a
 * locked snapshot.
 */
class OpenApiSchemaGenerator
{
    /**
     * Build the OpenAPI Schema Object for one canonical table.
     *
     * @param array $table Decoded canonical Table JSON.
     * @return array OpenAPI 3.0 Schema Object.
     */
    public function fromCanonicalTable(array $table): array
    {
        $properties = [];
        $required   = [];

        foreach ($table['fields'] ?? [] as $field) {
            $name = $field['name'] ?? null;
            if ($name === null) {
                continue;
            }

            $properties[$name] = $this->fromCanonicalField($field);

            // A field is "required" in the request body only if the DB
            // requires it AND the client is expected to supply it. Auto and
            // read-only fields are server-populated, so they're never required
            // input even when NOT NULL.
            if (!empty($field['required'])
                && empty($field['auto_increment'])
                && empty($field['read_only'])
            ) {
                $required[] = $name;
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => (object) $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = array_values($required);
        }
        if (!empty($table['description'])) {
            $schema['description'] = $table['description'];
        }

        return $schema;
    }

    /**
     * Build the OpenAPI property schema for one canonical field.
     *
     * @param array $field Decoded canonical Field JSON.
     * @return array OpenAPI 3.0 property Schema Object.
     */
    public function fromCanonicalField(array $field): array
    {
        $type   = $field['type'] ?? FieldSchema::TYPE_UNKNOWN;
        $schema = [];

        switch ($type) {
            case FieldSchema::TYPE_ID:
                // Don't force readOnly from the type alone — a foreign-key
                // column is often typed `id` but is client-writable. ReadOnly
                // is decided below from the auto_increment / read_only flags,
                // which correctly fire for the auto-generated primary key but
                // not for FK columns.
                $schema['type']   = 'integer';
                $schema['format'] = 'int64';
                break;

            case FieldSchema::TYPE_INTEGER:
                $schema['type']   = 'integer';
                $schema['format'] = 'int64';
                break;

            case FieldSchema::TYPE_NUMBER:
                $schema['type'] = 'number';
                break;

            case FieldSchema::TYPE_BOOLEAN:
                $schema['type'] = 'boolean';
                break;

            case FieldSchema::TYPE_STRING:
                $schema['type'] = 'string';
                if (!empty($field['length'])) {
                    $schema['maxLength'] = (int) $field['length'];
                }
                break;

            case FieldSchema::TYPE_TEXT:
                $schema['type'] = 'string';
                break;

            case FieldSchema::TYPE_DATE:
                $schema['type']   = 'string';
                $schema['format'] = 'date';
                break;

            case FieldSchema::TYPE_DATETIME:
                $schema['type']   = 'string';
                $schema['format'] = 'date-time';
                break;

            case FieldSchema::TYPE_TIME:
                // Not a standardized OpenAPI format but widely understood.
                $schema['type']   = 'string';
                $schema['format'] = 'time';
                break;

            case FieldSchema::TYPE_BINARY:
                $schema['type']   = 'string';
                $schema['format'] = 'binary';
                break;

            case FieldSchema::TYPE_JSON:
            case FieldSchema::TYPE_OBJECT:
                $schema['type'] = 'object';
                break;

            case FieldSchema::TYPE_ARRAY:
                $schema['type']  = 'array';
                $schema['items'] = $this->elementSchema($field['element_type'] ?? null);
                break;

            case FieldSchema::TYPE_GEOMETRY:
                // No portable OpenAPI representation; expose as object with a hint.
                $schema['type'] = 'object';
                break;

            case FieldSchema::TYPE_UNKNOWN:
            default:
                // Leave unconstrained ({}). A free-form value.
                break;
        }

        if (!empty($field['allow_null'])) {
            $schema['nullable'] = true;
        }

        // Both auto-increment and read-only fields are server-managed.
        if (!empty($field['auto_increment']) || !empty($field['read_only'])) {
            $schema['readOnly'] = true;
        }

        if (!empty($field['enum']['values']) && is_array($field['enum']['values'])) {
            $schema['enum'] = array_values($field['enum']['values']);
        }

        if (array_key_exists('default', $field) && $field['default'] !== null) {
            $schema['default'] = $field['default'];
        }

        if (!empty($field['description'])) {
            $schema['description'] = $field['description'];
        }

        return $schema;
    }

    /**
     * Map a canonical array element_type to the items schema. Falls back to
     * an unconstrained value when the element type is unknown (Phase 1
     * connectors don't populate element_type yet).
     */
    private function elementSchema(?string $elementType): object|array
    {
        if ($elementType === null) {
            return (object) []; // {} — any value
        }
        // Reuse field mapping by faking a minimal field of that type.
        return $this->fromCanonicalField(['type' => $elementType]);
    }

    /**
     * Build a `components/schemas` map for a whole canonical service.
     * Schema names prefer the canonical `openapi.schema_name` when present,
     * otherwise derive a PascalCase name from service + table.
     *
     * @param array  $service     Decoded canonical ServiceSchema JSON.
     * @param string $serviceName Service name used for name derivation.
     * @return array<string,array> schemaName => OpenAPI Schema Object
     */
    public function componentsForService(array $service, string $serviceName): array
    {
        $schemas = [];
        $tables = array_merge($service['tables'] ?? [], $service['views'] ?? []);

        foreach ($tables as $table) {
            $name = $this->schemaName($serviceName, $table);
            $schemas[$name] = $this->fromCanonicalTable($table);
        }

        return $schemas;
    }

    /**
     * Derive a stable OpenAPI schema name for a table. Honors a
     * pre-computed `openapi.schema_name` if a connector ever sets one.
     */
    public function schemaName(string $serviceName, array $table): string
    {
        $explicit = $table['openapi']['schema_name'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $parts = array_filter([
            $serviceName,
            $table['schema'] ?? null,
            $table['name'] ?? null,
        ]);

        $pascal = '';
        foreach ($parts as $part) {
            foreach (preg_split('/[^A-Za-z0-9]+/', (string) $part) as $word) {
                if ($word !== '') {
                    $pascal .= ucfirst(strtolower($word));
                }
            }
        }

        return $pascal !== '' ? $pascal : 'Resource';
    }
}
