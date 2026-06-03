<?php

namespace DreamFactory\Core\SchemaContracts\Normalization;

use DreamFactory\Core\Database\Schema\ColumnSchema as DfColumnSchema;
use DreamFactory\Core\Database\Schema\RelationSchema as DfRelationSchema;
use DreamFactory\Core\Database\Schema\TableSchema as DfTableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Facades\ServiceManager;
use DreamFactory\Core\SchemaContracts\Canonical\FieldSchema;
use DreamFactory\Core\SchemaContracts\Canonical\RelationshipSchema;
use DreamFactory\Core\SchemaContracts\Canonical\TableSchema as CanonicalTableSchema;

/**
 * Maps DreamFactory's connector-side schema objects into the canonical
 * connector-neutral shape used by df-schema-contracts.
 *
 * This is the Phase 1 consumer-only mapper: it reads whatever each connector's
 * `Schema::loadTableColumns()` already exposes, plus the schema_extras layer
 * that BaseDbService::getTableSchema() merges on top. See
 * `docs/CANONICAL_SCHEMA_JSON.md` "Phase 1 fidelity limit" for known gaps
 * that close in Phase 1.5 when each connector starts pushing its
 * vendor-specific metadata into the `native` bag.
 */
class Normalizer
{
    public function normalizeTable(DfTableSchema $table): CanonicalTableSchema
    {
        $fields = [];
        foreach ($table->getColumns() as $column) {
            $fields[] = $this->normalizeField($column);
        }

        $relationships = [];
        foreach ($table->getRelations() as $relation) {
            $relationships[] = $this->normalizeRelationship($relation);
        }

        return new CanonicalTableSchema(
            name: $table->getName(),
            catalog: $table->catalogName ?: null,
            schema: $table->schemaName ?: null,
            label: $table->getLabel(),
            type: $table->isView
                ? CanonicalTableSchema::TYPE_VIEW
                : CanonicalTableSchema::TYPE_TABLE,
            description: $table->description ?: null,
            fields: $fields,
            primaryKey: array_values((array) $table->primaryKey),
            indexes: [],
            relationships: $relationships,
            openapi: [],
            native: is_array($table->native) ? $table->native : [],
        );
    }

    public function normalizeField(DfColumnSchema $column): FieldSchema
    {
        $canonicalType = $this->mapType($column->type, $column->dbType);

        // Prefer admin-UI description (db_field_extras) over the DB-native
        // comment, but fall back to comment when no extras description exists.
        $description = $column->description
            ?: (!empty($column->comment) ? $column->comment : null);

        $ref = null;
        if ($column->isForeignKey && !empty($column->refTable)) {
            $ref = [
                'service' => null, // FK refs are intra-service in DF
                'schema'  => null,
                'table'   => (string) $column->refTable,
                'field'   => (string) $column->refField,
            ];
        }

        $enum = $this->extractEnum($column);

        // Virtual columns (added via the admin UI schema extras) are reported
        // as generated. Their compute expression, if any, lives in dbFunction.
        $generated = null;
        if ($column->isVirtual) {
            $expression = null;
            if (is_array($column->dbFunction) && !empty($column->dbFunction[0]['function'])) {
                $expression = (string) $column->dbFunction[0]['function'];
            }
            $generated = [
                'kind'       => 'virtual',
                'expression' => $expression,
            ];
        }

        // "Cannot be written by clients." Auto-increment columns are excluded
        // because clients CAN supply a value; auto_increment has its own flag
        // for the "don't supply on insert" hint.
        $readOnly = (bool) ($column->isAggregate || $column->isVirtual);

        // For integer / boolean / id types the connector's `size` is usually
        // the display-width hint (e.g. MySQL `int(11)`), not a storage size.
        // Drop it to avoid misleading consumers; db_type retains the raw form.
        // String / text / binary lengths keep their meaning.
        $length = $column->size !== null ? (int) $column->size : null;
        if (in_array($canonicalType, [
            FieldSchema::TYPE_INTEGER,
            FieldSchema::TYPE_BOOLEAN,
            FieldSchema::TYPE_ID,
        ], true)) {
            $length = null;
        }

        // DreamFactory db_function wrappers (geometry casts, sql_variant
        // handling, uuid SELECT formatting, etc.) are DF-specific behavior
        // worth preserving for drift; SDK and OpenAPI generators can ignore.
        $native = is_array($column->native) ? $column->native : [];
        if (!empty($column->dbFunction)) {
            $native['db_function'] = $column->dbFunction;
        }

        return new FieldSchema(
            name: $column->getName(),
            alias: $column->alias ?: null,
            label: $column->getLabel(),
            description: $description,
            type: $canonicalType,
            elementType: null,
            dbType: $column->dbType ?: null,
            nativeType: null,
            length: $length,
            precision: $column->precision !== null ? (int) $column->precision : null,
            scale: $column->scale !== null ? (int) $column->scale : null,
            default: $column->defaultValue,
            required: (bool) $column->getRequired(),
            allowNull: (bool) $column->allowNull,
            autoIncrement: (bool) $column->autoIncrement,
            readOnly: $readOnly,
            generated: $generated,
            isPrimaryKey: (bool) $column->isPrimaryKey,
            isUnique: (bool) $column->isUnique,
            isIndex: (bool) $column->isIndex,
            isForeignKey: (bool) $column->isForeignKey,
            ref: $ref,
            enum: $enum,
            validation: $column->validation,
            openapi: [],
            native: $native,
        );
    }

    public function normalizeRelationship(DfRelationSchema $relation): RelationshipSchema
    {
        $type = match ($relation->type) {
            DfRelationSchema::BELONGS_TO => RelationshipSchema::TYPE_BELONGS_TO,
            DfRelationSchema::HAS_ONE    => RelationshipSchema::TYPE_HAS_ONE,
            DfRelationSchema::HAS_MANY   => RelationshipSchema::TYPE_HAS_MANY,
            DfRelationSchema::MANY_MANY  => RelationshipSchema::TYPE_MANY_MANY,
            default                      => RelationshipSchema::TYPE_HAS_MANY,
        };

        $junction = null;
        if (!empty($relation->junctionTable)) {
            $junction = [
                'service'   => $this->resolveServiceName($relation->junctionServiceId),
                'schema'    => null,
                'table'     => (string) $relation->junctionTable,
                'field'     => $this->joinIfArray($relation->junctionField),
                'ref_field' => $this->joinIfArray($relation->junctionRefField),
            ];
        }

        return new RelationshipSchema(
            name: $relation->getName(),
            label: $relation->getLabel(),
            type: $type,
            field: $this->joinIfArray($relation->field),
            refService: $this->resolveServiceName($relation->refServiceId),
            refSchema: null,
            refTable: (string) $relation->refTable,
            refField: $this->joinIfArray($relation->refField),
            junction: $junction,
            isVirtual: (bool) $relation->isVirtual,
            alwaysFetch: (bool) $relation->alwaysFetch,
            native: [],
        );
    }

    /**
     * Collapse DreamFactory's fine-grained DbSimpleTypes (bigint, smallint,
     * decimal, mediumtext, etc.) into the canonical small set. Vendor-side
     * refinement (json/jsonb, geometry/geography) is applied after the simple
     * map as a Phase 1 consumer-side shim until per-connector enrichment.
     */
    private function mapType(?string $dfType, ?string $dbType): string
    {
        $canonical = match ($dfType) {
            DbSimpleTypes::TYPE_ID,
            DbSimpleTypes::TYPE_BIG_ID,
            DbSimpleTypes::TYPE_MEDIUM_ID,
            DbSimpleTypes::TYPE_SMALL_ID,
            DbSimpleTypes::TYPE_USER_ID,
            DbSimpleTypes::TYPE_USER_ID_ON_CREATE,
            DbSimpleTypes::TYPE_USER_ID_ON_UPDATE,
            DbSimpleTypes::TYPE_REF
                => FieldSchema::TYPE_ID,

            DbSimpleTypes::TYPE_INTEGER,
            DbSimpleTypes::TYPE_BIG_INT,
            DbSimpleTypes::TYPE_MEDIUM_INTEGER,
            DbSimpleTypes::TYPE_SMALL_INT,
            DbSimpleTypes::TYPE_TINY_INT
                => FieldSchema::TYPE_INTEGER,

            DbSimpleTypes::TYPE_DECIMAL,
            DbSimpleTypes::TYPE_FLOAT,
            DbSimpleTypes::TYPE_DOUBLE,
            DbSimpleTypes::TYPE_MONEY
                => FieldSchema::TYPE_NUMBER,

            DbSimpleTypes::TYPE_STRING
                => FieldSchema::TYPE_STRING,

            DbSimpleTypes::TYPE_TEXT,
            DbSimpleTypes::TYPE_MEDIUM_TEXT,
            DbSimpleTypes::TYPE_LONG_TEXT
                => FieldSchema::TYPE_TEXT,

            DbSimpleTypes::TYPE_BOOLEAN
                => FieldSchema::TYPE_BOOLEAN,

            DbSimpleTypes::TYPE_DATE
                => FieldSchema::TYPE_DATE,

            DbSimpleTypes::TYPE_DATETIME,
            DbSimpleTypes::TYPE_DATETIME_TZ,
            DbSimpleTypes::TYPE_TIMESTAMP,
            DbSimpleTypes::TYPE_TIMESTAMP_TZ,
            DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE,
            DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE
                => FieldSchema::TYPE_DATETIME,

            DbSimpleTypes::TYPE_TIME,
            DbSimpleTypes::TYPE_TIME_TZ
                => FieldSchema::TYPE_TIME,

            DbSimpleTypes::TYPE_BINARY
                => FieldSchema::TYPE_BINARY,

            DbSimpleTypes::TYPE_JSON,
            DbSimpleTypes::TYPE_JSONB
                => FieldSchema::TYPE_JSON,

            DbSimpleTypes::TYPE_ARRAY
                => FieldSchema::TYPE_ARRAY,

            DbSimpleTypes::TYPE_OBJECT,
            DbSimpleTypes::TYPE_ROW
                => FieldSchema::TYPE_OBJECT,

            default => FieldSchema::TYPE_UNKNOWN,
        };

        if ($dbType !== null && in_array($canonical, [FieldSchema::TYPE_STRING, FieldSchema::TYPE_UNKNOWN], true)) {
            $lower = strtolower($dbType);
            if ($lower === 'json' || $lower === 'jsonb' || str_starts_with($lower, 'json(')) {
                return FieldSchema::TYPE_JSON;
            }
            $geometryTypes = ['geometry', 'geography', 'point', 'polygon', 'linestring',
                              'multipoint', 'multipolygon', 'multilinestring', 'geometrycollection'];
            if (in_array($lower, $geometryTypes, true)
                || str_starts_with($lower, 'geometry(')
                || str_starts_with($lower, 'geography(')
            ) {
                return FieldSchema::TYPE_GEOMETRY;
            }
        }

        return $canonical;
    }

    /**
     * Resolve a service id to its name via the ServiceManager facade. Returns
     * null when the id is empty or the lookup fails, so consumers never see a
     * partially-resolved reference.
     */
    private function resolveServiceName($serviceId): ?string
    {
        if (empty($serviceId)) {
            return null;
        }
        try {
            $name = ServiceManager::getServiceNameById((int) $serviceId);
            return $name ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build the enum descriptor. Prefers the admin-UI picklist (db_field_extras),
     * then falls back to extracting MySQL/MariaDB `enum(...)` / `set(...)`
     * values straight from db_type. The fallback exists because no SQL
     * connector currently populates picklist from native ENUM/SET columns
     * (see CANONICAL_SCHEMA_JSON.md "Phase 1 fidelity limit"). Once Phase 1.5
     * MySQL enrichment lands, the regex path becomes redundant.
     */
    private function extractEnum(DfColumnSchema $column): ?array
    {
        if (!empty($column->picklist) && is_array($column->picklist)) {
            return [
                'values' => array_values($column->picklist),
                'name'   => null,
            ];
        }

        if (!is_string($column->dbType)) {
            return null;
        }

        if (!preg_match('/^(enum|set)\(\s*(.+)\s*\)$/i', $column->dbType, $matches)) {
            return null;
        }

        $values = [];
        if (preg_match_all("/'((?:[^']|'')*)'/", $matches[2], $valueMatches)) {
            foreach ($valueMatches[1] as $value) {
                $values[] = str_replace("''", "'", $value);
            }
        }

        return empty($values)
            ? null
            : ['values' => $values, 'name' => null];
    }

    private function joinIfArray($value): string
    {
        if (is_array($value)) {
            return implode(',', $value);
        }
        return (string) ($value ?? '');
    }
}
