<?php

namespace DreamFactory\Core\SchemaContracts\Contracts;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\SchemaContracts\Canonical\ServiceSchema;
use DreamFactory\Core\SchemaContracts\Canonical\TableSchema;

interface CanonicalSchemaAdapter
{
    /**
     * Stable adapter identifier. Used in logs, parity tests, and snapshot
     * provenance. Examples: "default-sql", "postgres", "snowflake".
     */
    public function name(): string;

    /**
     * Adapter priority. Higher priority wins when multiple adapters claim the
     * same service. The default consumer-only adapter must return 0;
     * vendor-specific adapters should return > 0.
     */
    public function priority(): int;

    /**
     * Whether this adapter can produce a canonical schema for the service.
     */
    public function supports(ServiceInterface $service): bool;

    /**
     * Produce the canonical envelope for an entire SQL service.
     *
     * Implementations must not mutate the service or trigger side effects on
     * the underlying database.
     */
    public function describeService(ServiceInterface $service): ServiceSchema;

    /**
     * Produce the canonical shape for a single table or view.
     *
     * Used by drift checks and the candidate-test endpoint to avoid pulling
     * the whole service when only one object is being inspected.
     *
     * @param string|null $schema When null, the service's default schema is used.
     */
    public function describeTable(
        ServiceInterface $service,
        string $name,
        ?string $schema = null
    ): TableSchema;
}
