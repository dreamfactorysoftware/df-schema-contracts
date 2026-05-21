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

    protected $table = 'schema_contract_service';

    protected $fillable = [
        'service_id',
        'service_name',
        'mode',
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
}
