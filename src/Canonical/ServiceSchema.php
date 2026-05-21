<?php

namespace DreamFactory\Core\SchemaContracts\Canonical;

final class ServiceSchema implements \JsonSerializable
{
    public const VERSION = '1.0';

    /**
     * @param TableSchema[] $tables
     * @param TableSchema[] $views
     * @param string[]      $procedures
     * @param array<string,mixed> $native
     */
    public function __construct(
        public readonly int $serviceId,
        public readonly string $serviceName,
        public readonly string $serviceType,
        public readonly ?string $serviceLabel,
        public readonly string $databaseVendor,
        public readonly ?string $serverVersion,
        public readonly ?string $defaultSchema,
        public readonly string $generatedAt,
        public readonly array $tables = [],
        public readonly array $views = [],
        public readonly array $procedures = [],
        public readonly array $native = [],
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'version' => self::VERSION,
            'service' => [
                'id'    => $this->serviceId,
                'name'  => $this->serviceName,
                'type'  => $this->serviceType,
                'label' => $this->serviceLabel,
            ],
            'database' => [
                'vendor'         => $this->databaseVendor,
                'server_version' => $this->serverVersion,
                'default_schema' => $this->defaultSchema,
            ],
            'generated_at' => $this->generatedAt,
            'tables'       => $this->tables,
            'views'        => $this->views,
            'procedures'   => $this->procedures,
            'native'       => (object) $this->native,
        ];
    }
}
