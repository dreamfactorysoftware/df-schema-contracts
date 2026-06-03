<?php

namespace DreamFactory\Core\SchemaContracts\Adapters;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\Database\Services\BaseDbService;
use DreamFactory\Core\SchemaContracts\Canonical\ServiceSchema;
use DreamFactory\Core\SchemaContracts\Canonical\TableSchema;
use DreamFactory\Core\SchemaContracts\Contracts\CanonicalSchemaAdapter;
use DreamFactory\Core\SchemaContracts\Normalization\Normalizer;
use RuntimeException;

/**
 * Consumer-only adapter that builds canonical schema from the metadata each
 * connector already exposes via Schema::loadTableColumns().
 *
 * Fidelity is bounded by what the underlying connector populates on
 * ColumnSchema / TableSchema / RelationSchema. See
 * docs/CANONICAL_SCHEMA_JSON.md "Phase 1 fidelity limit" for the catalogue of
 * known gaps; closing them is Phase 1.5 work that happens inside each
 * connector package.
 */
class DefaultSqlAdapter implements CanonicalSchemaAdapter
{
    /**
     * Service types this adapter handles. `BaseDbService` is shared by SQL
     * AND NoSQL connectors (MongoDB, Cassandra, CouchDB all extend it), so
     * an `instanceof` check alone would wrongly claim NoSQL services — whose
     * collections don't map to the canonical table/column/relationship
     * model. We gate on the service type instead. Keep this aligned with the
     * UI's SQL_SERVICE_TYPES set in df-manage-schema-contracts.component.ts.
     */
    public const SQL_SERVICE_TYPES = [
        'mysql',
        'mariadb',
        'pgsql',
        'sqlite',
        'sqlsrv',
        'oracle',
        'snowflake',
        'ibmdb2',
        'informix',
        'firebird',
        'sqlanywhere',
        'memsql',
        'redshift',
        'alloydb',
        'databricks',
        'trino',
        'hana',
        'dremio',
    ];

    public function __construct(private ?Normalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new Normalizer();
    }

    public function name(): string
    {
        return 'default-sql';
    }

    public function priority(): int
    {
        return 0;
    }

    public function supports(ServiceInterface $service): bool
    {
        // Must be a DB service AND a SQL type — NoSQL DB services also
        // extend BaseDbService but aren't relational.
        return $service instanceof BaseDbService
            && in_array(strtolower((string) $service->getType()), self::SQL_SERVICE_TYPES, true);
    }

    public function describeService(ServiceInterface $service): ServiceSchema
    {
        $this->assertSupported($service);
        /** @var BaseDbService $service */

        $tables = [];
        $views = [];

        foreach ($service->getTableNames() as $tableInfo) {
            $name = $tableInfo->name;

            try {
                $dfTable = $service->getTableSchema($name);
            } catch (\Throwable $e) {
                // Skip tables we cannot describe (permission denied, dropped
                // mid-iteration, etc). Phase 1 surfaces this as omission; a
                // future phase can collect these as warnings.
                continue;
            }

            if ($dfTable === null) {
                continue;
            }

            $canonical = $this->normalizer->normalizeTable($dfTable);
            if ($canonical->type === TableSchema::TYPE_VIEW) {
                $views[] = $canonical;
            } else {
                $tables[] = $canonical;
            }
        }

        return new ServiceSchema(
            serviceId: $service->getServiceId(),
            serviceName: $service->getName(),
            serviceType: $service->getType(),
            serviceLabel: $service->getLabel() ?: null,
            databaseVendor: $service->getType(),
            serverVersion: null,
            defaultSchema: $service->getDefaultSchema() ?: null,
            generatedAt: gmdate('Y-m-d\TH:i:s\Z'),
            tables: $tables,
            views: $views,
            procedures: [],
            native: [],
        );
    }

    public function describeTable(
        ServiceInterface $service,
        string $name,
        ?string $schema = null
    ): TableSchema {
        $this->assertSupported($service);
        /** @var BaseDbService $service */

        $qualified = $schema ? ($schema . '.' . $name) : $name;
        $dfTable = $service->getTableSchema($qualified);

        if ($dfTable === null && $schema === null) {
            $defaultSchema = $service->getDefaultSchema();
            if ($defaultSchema) {
                $dfTable = $service->getTableSchema($defaultSchema . '.' . $name);
            }
        }

        if ($dfTable === null) {
            throw new RuntimeException(
                "Table '{$name}' not found in service '{$service->getName()}'."
            );
        }

        return $this->normalizer->normalizeTable($dfTable);
    }

    private function assertSupported(ServiceInterface $service): void
    {
        if (!$this->supports($service)) {
            throw new RuntimeException(
                'DefaultSqlAdapter does not support service of type '
                . get_class($service)
            );
        }
    }
}
