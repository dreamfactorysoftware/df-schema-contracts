<?php

namespace DreamFactory\Core\SchemaContracts\Canonical;

final class FieldSchema implements \JsonSerializable
{
    public const TYPE_ID       = 'id';
    public const TYPE_STRING   = 'string';
    public const TYPE_TEXT     = 'text';
    public const TYPE_INTEGER  = 'integer';
    public const TYPE_NUMBER   = 'number';
    public const TYPE_BOOLEAN  = 'boolean';
    public const TYPE_DATE     = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_TIME     = 'time';
    public const TYPE_BINARY   = 'binary';
    public const TYPE_JSON     = 'json';
    public const TYPE_ARRAY    = 'array';
    public const TYPE_OBJECT   = 'object';
    public const TYPE_GEOMETRY = 'geometry';
    public const TYPE_UNKNOWN  = 'unknown';

    /**
     * @param array{kind:string,expression:?string}|null $generated
     *        kind in {virtual, stored, identity, default_expr, computed, unknown}
     * @param array{
     *     service:?string,
     *     schema:?string,
     *     table:string,
     *     field:string
     * }|null $ref
     * @param array{values:array<int,scalar>,name:?string}|null $enum
     * @param array<string,mixed> $openapi
     * @param array<string,mixed> $native
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $label,
        public readonly ?string $description,
        public readonly string $type,
        public readonly ?string $elementType,
        public readonly ?string $dbType,
        public readonly ?string $nativeType,
        public readonly ?int $length,
        public readonly ?int $precision,
        public readonly ?int $scale,
        public readonly mixed $default,
        public readonly bool $required,
        public readonly bool $allowNull,
        public readonly bool $autoIncrement,
        public readonly bool $readOnly,
        public readonly ?array $generated,
        public readonly bool $isPrimaryKey,
        public readonly bool $isUnique,
        public readonly bool $isIndex,
        public readonly bool $isForeignKey,
        public readonly ?array $ref,
        public readonly ?array $enum,
        public readonly mixed $validation,
        public readonly array $openapi = [],
        public readonly array $native = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name'           => $this->name,
            'label'          => $this->label,
            'description'    => $this->description,
            'type'           => $this->type,
            'element_type'   => $this->elementType,
            'db_type'        => $this->dbType,
            'native_type'    => $this->nativeType,
            'length'         => $this->length,
            'precision'      => $this->precision,
            'scale'          => $this->scale,
            'default'        => $this->default,
            'required'       => $this->required,
            'allow_null'     => $this->allowNull,
            'auto_increment' => $this->autoIncrement,
            'read_only'      => $this->readOnly,
            'generated'      => $this->generated,
            'is_primary_key' => $this->isPrimaryKey,
            'is_unique'      => $this->isUnique,
            'is_index'       => $this->isIndex,
            'is_foreign_key' => $this->isForeignKey,
            'ref'            => $this->ref,
            'enum'           => $this->enum,
            'validation'     => $this->validation,
            'openapi'        => (object) $this->openapi,
            'native'         => (object) $this->native,
        ];
    }
}
