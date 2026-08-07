<?php

namespace DreamFactory\Core\SchemaContracts\Canonical;

final class RelationshipSchema implements \JsonSerializable
{
    public const TYPE_BELONGS_TO = 'belongs_to';
    public const TYPE_HAS_MANY   = 'has_many';
    public const TYPE_HAS_ONE    = 'has_one';
    public const TYPE_MANY_MANY  = 'many_many';

    /**
     * @param array{
     *     service:?string,
     *     schema:?string,
     *     table:string,
     *     field:string,
     *     ref_field:string
     * }|null $junction
     * @param array<string,mixed> $native
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $alias,
        public readonly ?string $label,
        public readonly string $type,
        public readonly string $field,
        public readonly ?string $refService,
        public readonly ?string $refSchema,
        public readonly string $refTable,
        public readonly string $refField,
        public readonly ?array $junction,
        public readonly bool $isVirtual,
        public readonly bool $alwaysFetch,
        public readonly array $native = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name'         => $this->name,
            'alias'        => $this->alias,
            'label'        => $this->label,
            'type'         => $this->type,
            'field'        => $this->field,
            'ref_service'  => $this->refService,
            'ref_schema'   => $this->refSchema,
            'ref_table'    => $this->refTable,
            'ref_field'    => $this->refField,
            'junction'     => $this->junction,
            'is_virtual'   => $this->isVirtual,
            'always_fetch' => $this->alwaysFetch,
            'native'       => (object) $this->native,
        ];
    }
}
