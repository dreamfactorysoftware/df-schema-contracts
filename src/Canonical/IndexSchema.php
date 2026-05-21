<?php

namespace DreamFactory\Core\SchemaContracts\Canonical;

final class IndexSchema implements \JsonSerializable
{
    public const TYPE_PRIMARY = 'primary';
    public const TYPE_UNIQUE  = 'unique';
    public const TYPE_INDEX   = 'index';

    /**
     * @param string[]            $fields
     * @param array<string,mixed> $native
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $fields,
        public readonly bool $isPrimary,
        public readonly bool $isUnique,
        public readonly array $native = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name'       => $this->name,
            'type'       => $this->type,
            'fields'     => $this->fields,
            'is_primary' => $this->isPrimary,
            'is_unique'  => $this->isUnique,
            'native'     => (object) $this->native,
        ];
    }
}
