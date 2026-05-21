<?php

namespace DreamFactory\Core\SchemaContracts\Canonical;

final class TableSchema implements \JsonSerializable
{
    public const TYPE_TABLE             = 'table';
    public const TYPE_VIEW              = 'view';
    public const TYPE_MATERIALIZED_VIEW = 'materialized_view';
    public const TYPE_FOREIGN_TABLE     = 'foreign_table';

    /**
     * @param FieldSchema[]        $fields
     * @param string[]             $primaryKey
     * @param IndexSchema[]        $indexes
     * @param RelationshipSchema[] $relationships
     * @param array<string,mixed>  $openapi
     * @param array<string,mixed>  $native
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $catalog,
        public readonly ?string $schema,
        public readonly ?string $label,
        public readonly string $type,
        public readonly ?string $description,
        public readonly array $fields = [],
        public readonly array $primaryKey = [],
        public readonly array $indexes = [],
        public readonly array $relationships = [],
        public readonly array $openapi = [],
        public readonly array $native = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name'          => $this->name,
            'catalog'       => $this->catalog,
            'schema'        => $this->schema,
            'label'         => $this->label,
            'type'          => $this->type,
            'description'   => $this->description,
            'fields'        => $this->fields,
            'primary_key'   => $this->primaryKey,
            'indexes'       => $this->indexes,
            'relationships' => $this->relationships,
            'openapi'       => (object) $this->openapi,
            'native'        => (object) $this->native,
        ];
    }
}
