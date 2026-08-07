<?php

namespace DreamFactory\Core\SchemaContracts\Models;

use DreamFactory\Core\Models\BaseSystemModel;

/**
 * Per-service contract configuration row.
 *
 * A row exists only for services that have been actively configured (locked,
 * mode-changed). Absence of a row is read as `mode: 'none'` — see
 * docs/SYSTEM_API.md "Resolved decisions §1".
 *
 * @property int         $id
 * @property int         $service_id
 * @property string      $service_name
 * @property string      $mode                      'auto' | 'strict'
 * @property string      $runtime_enforcement       'off' | 'shape_response' | 'strict'
 * @property int|null    $archive_retention_count   NULL = keep all
 * @property bool        $enabled
 */
class SchemaContractService extends BaseSystemModel
{
    public const MODE_NONE   = 'none';
    public const MODE_AUTO   = 'auto';
    public const MODE_STRICT = 'strict';

    /** Modes that are persisted in the table. 'none' is represented by row absence. */
    public const STORED_MODES = [self::MODE_AUTO, self::MODE_STRICT];

    // Runtime enforcement levels — independent of `mode`. See the migration
    // and docs/SYSTEM_API.md for the distinction.
    public const ENFORCE_OFF            = 'off';
    public const ENFORCE_SHAPE_RESPONSE = 'shape_response';
    public const ENFORCE_STRICT         = 'strict';

    public const ENFORCEMENT_LEVELS = [
        self::ENFORCE_OFF,
        self::ENFORCE_SHAPE_RESPONSE,
        self::ENFORCE_STRICT,
    ];

    protected $table = 'schema_contract_service';

    protected $fillable = [
        'service_id',
        'service_name',
        'mode',
        'runtime_enforcement',
        'archive_retention_count',
        'enabled',
        'created_by_id',
        'last_modified_by_id',
    ];

    protected $casts = [
        'service_id'              => 'integer',
        'archive_retention_count' => 'integer',
        'enabled'                 => 'boolean',
    ];

    /**
     * Resolve the effective mode for a service id. Returns 'none' when no
     * row exists, matching the design's "absent row = none" rule.
     */
    public static function modeFor(int $serviceId): string
    {
        $row = static::query()->where('service_id', $serviceId)->first();
        return $row?->mode ?? self::MODE_NONE;
    }

    /**
     * Resolve runtime enforcement for a service by NAME (the identifier the
     * event handler has). Returns 'off' when no row exists. Looked up by
     * name because the runtime event path carries the service name, not id.
     */
    public static function enforcementForName(string $serviceName): string
    {
        $row = static::query()->where('service_name', $serviceName)->first();
        return $row?->runtime_enforcement ?? self::ENFORCE_OFF;
    }
}
