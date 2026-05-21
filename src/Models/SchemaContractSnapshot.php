<?php

namespace DreamFactory\Core\SchemaContracts\Models;

use DreamFactory\Core\Models\BaseSystemModel;

/**
 * Locked canonical-schema snapshot for one table at one version.
 *
 * Snapshots are immutable once written. Lifecycle is a two-state machine
 * (`active` -> `archived`) — candidate snapshots are computed not persisted,
 * see docs/SYSTEM_API.md "Test / promote flow".
 *
 * @property int         $id
 * @property int         $service_id
 * @property string      $service_name
 * @property string|null $table_catalog
 * @property string|null $table_schema
 * @property string      $table_name
 * @property string      $object_type        'table' | 'view' | 'materialized_view' | 'foreign_table'
 * @property int         $contract_version
 * @property string      $schema_hash        SHA-256 hex of schema_json
 * @property string      $schema_json        Canonical Table JSON (string)
 * @property string      $status             'active' | 'archived'
 */
class SchemaContractSnapshot extends BaseSystemModel
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const OBJECT_TABLE             = 'table';
    public const OBJECT_VIEW              = 'view';
    public const OBJECT_MATERIALIZED_VIEW = 'materialized_view';
    public const OBJECT_FOREIGN_TABLE     = 'foreign_table';

    protected $table = 'schema_contract_snapshot';

    protected $fillable = [
        'service_id',
        'service_name',
        'table_catalog',
        'table_schema',
        'table_name',
        'object_type',
        'contract_version',
        'schema_hash',
        'schema_json',
        'status',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'service_id'       => 'integer',
        'contract_version' => 'integer',
    ];

    /**
     * The current active snapshot for a table, if any. Nullable identity
     * columns (`table_schema`, `table_catalog`) translate to IS NULL when
     * the argument is null, so the lookup keys match the way they were
     * stored at lock time.
     */
    public static function activeFor(
        int $serviceId,
        string $tableName,
        ?string $tableSchema = null,
        ?string $tableCatalog = null
    ): ?self {
        $query = static::query()
            ->where('service_id', $serviceId)
            ->where('table_name', $tableName)
            ->where('status', self::STATUS_ACTIVE);

        if ($tableSchema === null) {
            $query->whereNull('table_schema');
        } else {
            $query->where('table_schema', $tableSchema);
        }

        if ($tableCatalog === null) {
            $query->whereNull('table_catalog');
        } else {
            $query->where('table_catalog', $tableCatalog);
        }

        return $query->first();
    }

    /**
     * Compute the canonical hash for a canonical Table JSON string. Returning
     * the same hash for two snapshots means their canonical content is
     * byte-identical and they have no drift.
     */
    public static function hashCanonical(string $canonicalJson): string
    {
        return hash('sha256', $canonicalJson);
    }
}
