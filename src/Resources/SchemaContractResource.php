<?php

namespace DreamFactory\Core\SchemaContracts\Resources;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\Database\Services\BaseDbService;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Facades\ServiceManager;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Resources\BaseRestResource;
use DreamFactory\Core\SchemaContracts\Adapters\AdapterRegistry;
use DreamFactory\Core\SchemaContracts\Adapters\DefaultSqlAdapter;
use DreamFactory\Core\SchemaContracts\Canonical\TableSchema;
use DreamFactory\Core\SchemaContracts\Drift\DriftEngine;
use DreamFactory\Core\SchemaContracts\Drift\Kind;
use DreamFactory\Core\SchemaContracts\Drift\Severity;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractSnapshot;
use DreamFactory\Core\SchemaContracts\OpenApi\OpenApiSchemaGenerator;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Facades\DB;

/**
 * System resource for schema contract management. Routes under
 * `/api/v2/system/schema_contract/...` and dispatches by URL shape:
 *
 *   GET    /schema_contract/{service}/tables/{table}/snapshot
 *   POST   /schema_contract/{service}/tables/{table}/lock
 *
 * Every other endpoint enumerated in docs/SYSTEM_API.md returns 501 for now;
 * those land in later phases. URL identity follows the canonical rules
 * (canonical_schema_json.md "Canonical Identity"). Phase 1 reads use the
 * bare table name; multi-schema disambiguation lands when Postgres parity
 * is added.
 */
class SchemaContractResource extends BaseRestResource
{
    protected function handleGET()
    {
        $service = $this->resourceArray[0] ?? null;
        $sub     = $this->resourceArray[1] ?? null;
        $table   = $this->resourceArray[2] ?? null;
        $action  = $this->resourceArray[3] ?? null;

        if (!$service) {
            return $this->listAllServices();
        }

        if ($service && !$sub) {
            return $this->getServiceSummary($service);
        }

        if ($sub === 'tables' && $table && $action === 'snapshot') {
            return $this->getActiveSnapshot($service, $table);
        }

        if ($sub === 'tables' && $table && $action === 'diff') {
            return $this->getTableDiff($service, $table);
        }

        if ($sub === 'tables' && $table && $action === 'snapshots') {
            $version = $this->resourceArray[4] ?? null;
            if ($version !== null && $version !== '') {
                return $this->getSnapshotVersion($service, $table, (int) $version);
            }
            return $this->listSnapshots($service, $table);
        }

        if ($sub === 'tables' && $table && $action === 'openapi') {
            return $this->getTableOpenApi($service, $table);
        }

        if ($sub === 'tables' && !$table) {
            return $this->listTables($service);
        }

        if ($sub === 'diff' && !$table) {
            return $this->getServiceDiff($service);
        }

        if ($sub === 'openapi' && !$table) {
            return $this->getServiceOpenApi($service);
        }

        throw new NotFoundException("Unknown GET endpoint: /{$this->resourcePath}");
    }

    protected function handlePOST()
    {
        $service = $this->resourceArray[0] ?? null;
        $sub     = $this->resourceArray[1] ?? null;
        $table   = $this->resourceArray[2] ?? null;
        $action  = $this->resourceArray[3] ?? null;

        if ($service && $sub === 'tables' && $table && $action === 'lock') {
            return $this->lockTable($service, $table);
        }

        if ($service && $sub === 'tables' && $table && $action === 'test') {
            return $this->testCandidate($service, $table);
        }

        if ($service && $sub === 'promote' && !$table) {
            return $this->promoteService($service);
        }

        throw new BadRequestException(
            "Unknown POST endpoint: /{$this->resourcePath}"
        );
    }

    protected function handleDELETE()
    {
        $service = $this->resourceArray[0] ?? null;
        $sub     = $this->resourceArray[1] ?? null;
        $table   = $this->resourceArray[2] ?? null;

        if ($service && $sub === 'tables' && $table) {
            return $this->unlockTable($service, $table);
        }

        if ($service && !$sub) {
            return $this->unlockService($service);
        }

        throw new BadRequestException(
            "Unknown DELETE endpoint: /{$this->resourcePath}"
        );
    }

    protected function handlePATCH()
    {
        $service = $this->resourceArray[0] ?? null;
        $sub     = $this->resourceArray[1] ?? null;

        if ($service && !$sub) {
            return $this->updateServiceConfig($service);
        }

        throw new BadRequestException(
            "Unknown PATCH endpoint: /{$this->resourcePath}"
        );
    }

    /**
     * PUT is aliased to PATCH at the BaseRestResource level. We accept both
     * so REST clients using either verb get the same behaviour.
     */
    protected function handlePUT()
    {
        return $this->handlePATCH();
    }

    /**
     * Lock a single table. Snapshots are versioned per (service, table
     * identity); a re-lock at the same canonical content is a no-op (returns
     * the existing active row) unless `force=1` is passed.
     */
    private function lockTable(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        try {
            $adapter   = app(AdapterRegistry::class)->for($service);
            $canonical = $adapter->describeTable($service, $tableName);
        } catch (\RuntimeException $e) {
            // DefaultSqlAdapter throws RuntimeException for "table not found".
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NotFoundException($e->getMessage());
            }
            throw new InternalServerErrorException($e->getMessage());
        } catch (\Throwable $e) {
            throw new InternalServerErrorException(
                "Describe failed for '{$serviceName}/{$tableName}': " . $e->getMessage()
            );
        }

        $json   = json_encode($canonical, JSON_UNESCAPED_SLASHES);
        $hash   = SchemaContractSnapshot::hashCanonical($json);
        $force  = $this->request->getParameterAsBool('force');

        $existing = SchemaContractSnapshot::activeFor(
            $service->getServiceId(),
            $canonical->name,
            $canonical->schema,
            $canonical->catalog,
        );

        if ($existing && $existing->schema_hash === $hash && !$force) {
            return $this->formatSnapshot($existing, 'no_change');
        }

        $userId = $this->currentUserId();

        // Wrap archive + insert in a transaction so a partial update can't
        // leave two active rows for the same table identity.
        $newSnapshot = DB::transaction(function () use ($existing, $service, $canonical, $hash, $json, $userId) {
            if ($existing) {
                $existing->status = SchemaContractSnapshot::STATUS_ARCHIVED;
                if ($userId) {
                    $existing->last_modified_by_id = $userId;
                }
                $existing->save();
            }

            // Version is the next number after the highest *ever* used for
            // this table identity, not just the next after the current
            // active. This keeps versions monotonic across unlock/re-lock
            // cycles and avoids unique-constraint collisions when
            // (catalog, schema) are NULL on connectors like SQLite.
            $maxVersion = SchemaContractSnapshot::query()
                ->where('service_id', $service->getServiceId())
                ->where('table_name', $canonical->name)
                ->when($canonical->catalog === null,
                    fn ($q) => $q->whereNull('table_catalog'),
                    fn ($q) => $q->where('table_catalog', $canonical->catalog))
                ->when($canonical->schema === null,
                    fn ($q) => $q->whereNull('table_schema'),
                    fn ($q) => $q->where('table_schema', $canonical->schema))
                ->max('contract_version');
            $nextVersion = ((int) ($maxVersion ?? 0)) + 1;

            // Build with table_catalog / table_schema even when null
            // (legitimate value for SQLite); only the audit columns are
            // dropped when userId is unavailable, since the FK to user
            // requires either a real id or no value at all.
            $attrs = [
                'service_id'       => $service->getServiceId(),
                'service_name'     => $service->getName(),
                'table_catalog'    => $canonical->catalog,
                'table_schema'     => $canonical->schema,
                'table_name'       => $canonical->name,
                'object_type'      => $canonical->type,
                'contract_version' => $nextVersion,
                'schema_hash'      => $hash,
                'schema_json'      => $json,
                'status'           => SchemaContractSnapshot::STATUS_ACTIVE,
            ];
            if ($userId) {
                $attrs['created_by_id']       = $userId;
                $attrs['last_modified_by_id'] = $userId;
            }

            return SchemaContractSnapshot::create($attrs);
        });

        return $this->formatSnapshot(
            $newSnapshot,
            $existing ? 'promoted' : 'locked'
        );
    }

    /**
     * Preview what would happen if the user locked this table right now.
     * Side-effect-free dry run of `lockTable`. Returns the candidate
     * canonical JSON, the action that would result (`locked` / `promoted` /
     * `no_change`), the would-be version number, and a full drift report
     * comparing live against the active snapshot (or empty drift when no
     * active snapshot exists).
     *
     * Works whether or not the table is currently locked — that's the
     * primary distinction from `GET /diff`, which requires an active
     * snapshot.
     */
    private function testCandidate(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        try {
            $adapter   = app(AdapterRegistry::class)->for($service);
            $canonical = $adapter->describeTable($service, $tableName);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NotFoundException($e->getMessage());
            }
            throw new InternalServerErrorException($e->getMessage());
        } catch (\Throwable $e) {
            throw new InternalServerErrorException(
                "Describe failed for '{$serviceName}/{$tableName}': " . $e->getMessage()
            );
        }

        $candidateJson = json_encode($canonical, JSON_UNESCAPED_SLASHES);
        $candidate     = json_decode($candidateJson, true);
        $candidateHash = SchemaContractSnapshot::hashCanonical($candidateJson);

        $active = SchemaContractSnapshot::activeFor(
            $service->getServiceId(),
            $canonical->name,
            $canonical->schema,
            $canonical->catalog,
        );

        $engine = new DriftEngine();

        if (!$active) {
            // No prior lock — would be an initial lock, no drift to report.
            // Version number matches what `lockTable` would compute: 1 if
            // table has never been locked, else MAX(archived) + 1.
            $maxVersion = SchemaContractSnapshot::query()
                ->where('service_id', $service->getServiceId())
                ->where('table_name', $canonical->name)
                ->when($canonical->catalog === null,
                    fn ($q) => $q->whereNull('table_catalog'),
                    fn ($q) => $q->where('table_catalog', $canonical->catalog))
                ->when($canonical->schema === null,
                    fn ($q) => $q->whereNull('table_schema'),
                    fn ($q) => $q->where('table_schema', $canonical->schema))
                ->max('contract_version');
            $wouldBeVersion = ((int) ($maxVersion ?? 0)) + 1;

            return $this->wrapTestReport(
                $serviceName, $tableName,
                $wouldBeVersion, 'locked',
                null, null,
                $candidateHash,
                $engine->buildReport([]),
                $candidate
            );
        }

        if ($active->schema_hash === $candidateHash) {
            return $this->wrapTestReport(
                $serviceName, $tableName,
                (int) $active->contract_version, 'no_change',
                (int) $active->contract_version, $active->schema_hash,
                $candidateHash,
                $engine->buildReport([]),
                $candidate
            );
        }

        $report = $engine->compareTable(json_decode($active->schema_json, true), $candidate);

        return $this->wrapTestReport(
            $serviceName, $tableName,
            (int) $active->contract_version + 1, 'promoted',
            (int) $active->contract_version, $active->schema_hash,
            $candidateHash,
            $report,
            $candidate
        );
    }

    private function wrapTestReport(
        string $serviceName,
        string $tableName,
        int $wouldBeVersion,
        string $wouldBeAction,
        ?int $activeVersion,
        ?string $activeHash,
        string $candidateHash,
        array $report,
        array $candidate
    ): array {
        return [
            'service'                 => $serviceName,
            'table'                   => $tableName,
            'checked_at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'would_be_version'        => $wouldBeVersion,
            'would_be_action'         => $wouldBeAction,
            'active_snapshot_version' => $activeVersion,
            'active_snapshot_hash'    => $activeHash,
            'candidate_hash'          => $candidateHash,
            'has_drift'               => $report['has_drift'],
            'has_breaking'            => $report['has_breaking'],
            'summary'                 => $report['summary'],
            'changes'                 => $report['changes'],
            'candidate'               => $candidate,
        ];
    }

    /**
     * List every snapshot version for a table, newest first. Returns row
     * metadata only — does NOT include the canonical JSON to keep payload
     * small for tables with long histories. Fetch a specific version's
     * full content via `GET /tables/{table}/snapshots/{version}`.
     */
    private function listSnapshots(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        $snapshots = SchemaContractSnapshot::query()
            ->where('service_id', $service->getServiceId())
            ->where('table_name', $tableName)
            ->orderByDesc('contract_version')
            ->get();

        if ($snapshots->isEmpty()) {
            throw new NotFoundException(
                "No snapshots exist for '{$serviceName}/{$tableName}'."
            );
        }

        return [
            'service'  => $serviceName,
            'table'    => $tableName,
            'versions' => $snapshots->map(fn (SchemaContractSnapshot $s) => [
                'id'                 => (int) $s->id,
                'contract_version'   => (int) $s->contract_version,
                'status'             => $s->status,
                'object_type'        => $s->object_type,
                'table_catalog'      => $s->table_catalog,
                'table_schema'       => $s->table_schema,
                'schema_hash'        => $s->schema_hash,
                'created_date'       => $s->created_date?->toIso8601String(),
                'last_modified_date' => $s->last_modified_date?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Return a specific snapshot version (active OR archived), including the
     * full canonical JSON. Used by the history UI to view what an old
     * contract looked like.
     */
    private function getSnapshotVersion(
        string $serviceName,
        string $tableName,
        int $version
    ): array {
        $service = $this->resolveSqlService($serviceName);

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_id', $service->getServiceId())
            ->where('table_name', $tableName)
            ->where('contract_version', $version)
            ->first();

        if (!$snapshot) {
            throw new NotFoundException(
                "Version {$version} of '{$serviceName}/{$tableName}' not found."
            );
        }

        return $this->formatSnapshot($snapshot, null);
    }

    /**
     * Unlock a single table: archive its active snapshot. The snapshot row
     * is preserved (audit trail), only its status flips. Removing a
     * snapshot entirely is the `schema-contracts:prune` command's job, not
     * this endpoint's.
     *
     * Idempotent: if there is no active snapshot, returns a quiet 200 with
     * `result: "no_active_snapshot"` rather than 404, because the caller's
     * intent ("ensure this table is unlocked") has been satisfied.
     */
    private function unlockTable(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        $existing = SchemaContractSnapshot::query()
            ->where('service_id', $service->getServiceId())
            ->where('table_name', $tableName)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$existing) {
            return [
                'service' => $serviceName,
                'table'   => $tableName,
                'result'  => 'no_active_snapshot',
            ];
        }

        $existing->status = SchemaContractSnapshot::STATUS_ARCHIVED;
        if ($userId = $this->currentUserId()) {
            $existing->last_modified_by_id = $userId;
        }
        $existing->save();

        return [
            'service'                  => $serviceName,
            'table'                    => $tableName,
            'result'                   => 'unlocked',
            'archived_snapshot_id'     => (int) $existing->id,
            'archived_snapshot_version' => (int) $existing->contract_version,
        ];
    }

    /**
     * Auto-mode promote helper. Iterates active snapshots, computes drift,
     * and decides what to promote based on mode and severity:
     *
     *   - mode='none'   → 400; promotion not applicable
     *   - mode='auto'   → promote tables whose drift is additive/cosmetic
     *                     only; tables with breaking drift go to needs_review
     *   - mode='strict' → promote nothing; everything with drift goes to
     *                     needs_review for manual approval
     *
     * Tables without drift are reported under `skipped_no_drift`. Tables
     * that exist in snapshots but have been removed from live are always
     * needs_review (`table_removed` is breaking by definition).
     */
    private function promoteService(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();
        $userId    = $this->currentUserId();

        $config = SchemaContractService::query()
            ->where('service_id', $serviceId)
            ->first();
        $mode = $config?->mode ?? SchemaContractService::MODE_NONE;

        if ($mode === SchemaContractService::MODE_NONE) {
            throw new BadRequestException(
                "Service mode is 'none'. Promotion is not applicable — "
                . "set mode='auto' or 'strict' via PATCH /{service} first."
            );
        }

        $snapshots = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->get();

        if ($snapshots->isEmpty()) {
            return $this->emptyPromoteResult($serviceName, $mode);
        }

        try {
            $adapter  = app(AdapterRegistry::class)->for($service);
            $envelope = $adapter->describeService($service);
        } catch (\Throwable $e) {
            throw new InternalServerErrorException(
                "Describe failed for '{$serviceName}': " . $e->getMessage()
            );
        }

        $liveByIdentity = [];
        foreach (array_merge($envelope->tables, $envelope->views) as $tbl) {
            $arr = json_decode(json_encode($tbl, JSON_UNESCAPED_SLASHES), true);
            $liveByIdentity[$this->identityKey($arr['catalog'] ?? null, $arr['schema'] ?? null, $arr['name'])] = $arr;
        }

        $engine        = new DriftEngine();
        $promoted      = [];
        $needsReview   = [];
        $noDrift       = [];

        foreach ($snapshots as $snap) {
            $key  = $this->identityKey($snap->table_catalog, $snap->table_schema, $snap->table_name);
            $live = $liveByIdentity[$key] ?? null;

            if ($live === null) {
                $needsReview[] = [
                    'table'          => $snap->table_name,
                    'version'        => (int) $snap->contract_version,
                    'reason'         => 'table_removed',
                    'has_breaking'   => true,
                    'summary'        => [
                        'breaking_count'             => 1,
                        'potentially_breaking_count' => 0,
                        'additive_count'             => 0,
                        'cosmetic_count'             => 0,
                        'total_changes'              => 1,
                    ],
                ];
                continue;
            }

            $report = $engine->compareTable(json_decode($snap->schema_json, true), $live);

            if (!$report['has_drift']) {
                $noDrift[] = $snap->table_name;
                continue;
            }

            // strict mode never auto-promotes anything with drift.
            // auto mode promotes anything WITHOUT breaking drift.
            $shouldPromote = $mode === SchemaContractService::MODE_AUTO && !$report['has_breaking'];

            if (!$shouldPromote) {
                $needsReview[] = [
                    'table'        => $snap->table_name,
                    'version'      => (int) $snap->contract_version,
                    'reason'       => $report['has_breaking'] ? 'breaking_drift' : 'strict_mode',
                    'has_breaking' => $report['has_breaking'],
                    'summary'      => $report['summary'],
                ];
                continue;
            }

            // Auto-promote: archive old, insert new version.
            $candidateJson = json_encode($live, JSON_UNESCAPED_SLASHES);
            $candidateHash = SchemaContractSnapshot::hashCanonical($candidateJson);

            $result = DB::transaction(function () use ($snap, $service, $live, $candidateJson, $candidateHash, $userId) {
                $snap->status = SchemaContractSnapshot::STATUS_ARCHIVED;
                if ($userId) {
                    $snap->last_modified_by_id = $userId;
                }
                $snap->save();

                $maxVersion = SchemaContractSnapshot::query()
                    ->where('service_id', $service->getServiceId())
                    ->where('table_name', $snap->table_name)
                    ->when($snap->table_catalog === null,
                        fn ($q) => $q->whereNull('table_catalog'),
                        fn ($q) => $q->where('table_catalog', $snap->table_catalog))
                    ->when($snap->table_schema === null,
                        fn ($q) => $q->whereNull('table_schema'),
                        fn ($q) => $q->where('table_schema', $snap->table_schema))
                    ->max('contract_version');

                $attrs = [
                    'service_id'       => $service->getServiceId(),
                    'service_name'     => $service->getName(),
                    'table_catalog'    => $snap->table_catalog,
                    'table_schema'     => $snap->table_schema,
                    'table_name'       => $snap->table_name,
                    'object_type'      => $live['type'] ?? $snap->object_type,
                    'contract_version' => ((int) $maxVersion) + 1,
                    'schema_hash'      => $candidateHash,
                    'schema_json'      => $candidateJson,
                    'status'           => SchemaContractSnapshot::STATUS_ACTIVE,
                ];
                if ($userId) {
                    $attrs['created_by_id']       = $userId;
                    $attrs['last_modified_by_id'] = $userId;
                }
                return SchemaContractSnapshot::create($attrs);
            });

            $promoted[] = [
                'table'        => $snap->table_name,
                'from_version' => (int) $snap->contract_version,
                'to_version'   => (int) $result->contract_version,
            ];
        }

        return [
            'service' => $serviceName,
            'mode'    => $mode,
            'summary' => [
                'tables_evaluated'    => $snapshots->count(),
                'tables_promoted'     => count($promoted),
                'tables_needs_review' => count($needsReview),
                'tables_no_drift'     => count($noDrift),
            ],
            'promoted'         => $promoted,
            'needs_review'     => $needsReview,
            'skipped_no_drift' => $noDrift,
        ];
    }

    private function emptyPromoteResult(string $serviceName, string $mode): array
    {
        return [
            'service' => $serviceName,
            'mode'    => $mode,
            'summary' => [
                'tables_evaluated'    => 0,
                'tables_promoted'     => 0,
                'tables_needs_review' => 0,
                'tables_no_drift'     => 0,
            ],
            'promoted'         => [],
            'needs_review'     => [],
            'skipped_no_drift' => [],
        ];
    }

    /**
     * Update per-service config (mode + archive_retention_count).
     *
     * Per Decision #1, setting mode='none' DELETES the row entirely so the
     * "never configured" and "explicitly set to none" states are identical.
     * Setting mode to 'auto' or 'strict' upserts the row.
     *
     * Returns the post-update service summary, so the caller doesn't have
     * to round-trip to GET /{service} after a PATCH.
     */
    private function updateServiceConfig(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();
        $userId    = $this->currentUserId();

        $payload = (array) $this->request->getPayloadData();

        $newMode = array_key_exists('mode', $payload)
            ? (is_string($payload['mode']) ? strtolower(trim($payload['mode'])) : null)
            : null;

        $newRetention = array_key_exists('archive_retention_count', $payload)
            ? $payload['archive_retention_count']
            : null;

        $newEnforcement = array_key_exists('runtime_enforcement', $payload)
            ? (is_string($payload['runtime_enforcement']) ? strtolower(trim($payload['runtime_enforcement'])) : null)
            : null;

        $validModes = [
            SchemaContractService::MODE_NONE,
            SchemaContractService::MODE_AUTO,
            SchemaContractService::MODE_STRICT,
        ];
        if ($newMode !== null && !in_array($newMode, $validModes, true)) {
            throw new BadRequestException(
                "Invalid mode '{$newMode}'. Must be one of: " . implode(', ', $validModes)
            );
        }

        if ($newEnforcement !== null
            && !in_array($newEnforcement, SchemaContractService::ENFORCEMENT_LEVELS, true)
        ) {
            throw new BadRequestException(
                "Invalid runtime_enforcement '{$newEnforcement}'. Must be one of: "
                . implode(', ', SchemaContractService::ENFORCEMENT_LEVELS)
            );
        }

        if ($newRetention !== null && $newRetention !== '' && (
            !is_numeric($newRetention) || (int) $newRetention < 0
        )) {
            throw new BadRequestException(
                "archive_retention_count must be a non-negative integer or null."
            );
        }

        $existing = SchemaContractService::query()
            ->where('service_id', $serviceId)
            ->first();

        // mode='none' is represented by row absence. If the caller asked for
        // 'none' explicitly, remove the row.
        if ($newMode === SchemaContractService::MODE_NONE) {
            if ($existing) {
                $existing->delete();
            }
            return $this->getServiceSummary($serviceName);
        }

        // Anything else is an upsert. If mode wasn't provided AND the row
        // doesn't exist yet, we can't create one (need a mode to seed).
        if (!$existing && $newMode === null) {
            throw new BadRequestException(
                "Cannot configure a service without first setting mode. "
                . "Pass mode='auto' or mode='strict' to create the config row."
            );
        }

        $attrs = [
            'service_id'   => $serviceId,
            'service_name' => $service->getName(),
            'enabled'      => true,
        ];
        if ($newMode !== null) {
            $attrs['mode'] = $newMode;
        }
        if ($newEnforcement !== null) {
            $attrs['runtime_enforcement'] = $newEnforcement;
        }
        if (array_key_exists('archive_retention_count', $payload)) {
            $attrs['archive_retention_count'] = ($newRetention === '' || $newRetention === null)
                ? null
                : (int) $newRetention;
        }

        if ($existing) {
            if ($userId) {
                $attrs['last_modified_by_id'] = $userId;
            }
            $existing->fill($attrs);
            $existing->save();
        } else {
            if ($userId) {
                $attrs['created_by_id']       = $userId;
                $attrs['last_modified_by_id'] = $userId;
            }
            SchemaContractService::create($attrs);
        }

        return $this->getServiceSummary($serviceName);
    }

    /**
     * Unlock an entire service: archive every active snapshot AND remove
     * the per-service configuration row so the service returns to the
     * "never configured" state (Decision #1 in SYSTEM_API.md).
     *
     * Idempotent: if neither active snapshots nor a config row exist,
     * returns counts of 0 with HTTP 200.
     */
    private function unlockService(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();
        $userId    = $this->currentUserId();

        // Archive every active snapshot in one transaction so a half-applied
        // unlock can't leave some tables active and others archived.
        $archived = DB::transaction(function () use ($serviceId, $userId) {
            $rows = SchemaContractSnapshot::query()
                ->where('service_id', $serviceId)
                ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
                ->get();

            foreach ($rows as $row) {
                $row->status = SchemaContractSnapshot::STATUS_ARCHIVED;
                if ($userId) {
                    $row->last_modified_by_id = $userId;
                }
                $row->save();
            }

            return $rows->count();
        });

        // Remove the config row (mode + retention) so subsequent reads see
        // the service as "never configured".
        $configRemoved = (bool) SchemaContractService::query()
            ->where('service_id', $serviceId)
            ->delete();

        return [
            'service'                => $serviceName,
            'result'                 => 'unlocked',
            'snapshots_archived'     => $archived,
            'service_config_removed' => $configRemoved,
        ];
    }

    /**
     * Best-effort current-user lookup for audit columns. Returns null when
     * called outside a session (e.g. from an artisan command), in which
     * case the audit columns are left null — the FK constraint allows it.
     */
    private function currentUserId(): ?int
    {
        try {
            $id = Session::getCurrentUserId();
            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compute the drift between a table's active snapshot and the live
     * schema. Per Decision #2 in SYSTEM_API.md, table-level diff includes
     * the candidate (live canonical) inline so reviewers don't have to do
     * a follow-up round trip.
     *
     * Special case: if the table no longer exists in the database, the diff
     * report contains a single `table.removed` change. We still return 200
     * — drift IS the answer to a diff request, not an error.
     */
    private function getTableDiff(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_id', $service->getServiceId())
            ->where('table_name', $tableName)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$snapshot) {
            throw new NotFoundException(
                "No active snapshot for '{$serviceName}/{$tableName}' — lock the table before requesting drift."
            );
        }

        $active = json_decode($snapshot->schema_json, true);

        $live = null;
        $tableMissing = false;
        try {
            $adapter   = app(AdapterRegistry::class)->for($service);
            $canonical = $adapter->describeTable($service, $tableName);
            $live      = json_decode(json_encode($canonical, JSON_UNESCAPED_SLASHES), true);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                $tableMissing = true;
            } else {
                throw new InternalServerErrorException($e->getMessage());
            }
        } catch (\Throwable $e) {
            throw new InternalServerErrorException(
                "Describe failed for '{$serviceName}/{$tableName}': " . $e->getMessage()
            );
        }

        if ($tableMissing) {
            $engine = new DriftEngine();
            $report = $engine->buildReport([[
                'severity' => Severity::BREAKING,
                'kind'     => Kind::TABLE_REMOVED,
                'path'     => $tableName,
                'table'    => $tableName,
                'detail'   => ['old_table' => $active],
            ]]);
            return $this->wrapDiffReport($serviceName, $tableName, $snapshot, $report, null);
        }

        $engine = new DriftEngine();
        $report = $engine->compareTable($active, $live);

        return $this->wrapDiffReport($serviceName, $tableName, $snapshot, $report, $live);
    }

    private function wrapDiffReport(
        string $serviceName,
        string $tableName,
        SchemaContractSnapshot $snapshot,
        array $report,
        ?array $candidate
    ): array {
        return [
            'service'                 => $serviceName,
            'table'                   => $tableName,
            'checked_at'              => gmdate('Y-m-d\TH:i:s\Z'),
            'active_snapshot_version' => (int) $snapshot->contract_version,
            'active_snapshot_hash'    => $snapshot->schema_hash,
            'has_drift'               => $report['has_drift'],
            'has_breaking'            => $report['has_breaking'],
            'summary'                 => $report['summary'],
            'changes'                 => $report['changes'],
            'candidate'               => $candidate,
        ];
    }

    /**
     * Return the currently-active snapshot for a table. Phase 1: looks up by
     * table_name alone; multi-schema disambiguation is a future option via
     * query params (`?schema=public`) once Postgres parity ships.
     */
    private function getActiveSnapshot(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_id', $service->getServiceId())
            ->where('table_name', $tableName)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$snapshot) {
            throw new NotFoundException(
                "No active snapshot for '{$serviceName}/{$tableName}'."
            );
        }

        return $this->formatSnapshot($snapshot, null);
    }

    /**
     * Top-level dashboard query: every SQL service in the system, with its
     * contract-mode and snapshot counts. Cheap to compute — joins the
     * service catalog against `schema_contract_*` rows but does NOT call
     * any connector. Use `GET /{service}` for drift detail on one service.
     */
    private function listAllServices(): array
    {
        // SQL connector type names — keep this aligned with the UI's
        // SQL_SERVICE_TYPES set in df-manage-schema-contracts.component.ts.
        $sqlTypes = [
            'mysql', 'pgsql', 'sqlite', 'sqlsrv', 'oracle', 'snowflake',
            'ibmdb2', 'informix', 'firebird', 'sqlanywhere', 'memsql',
            'databricks', 'trino', 'hana', 'dremio',
        ];

        $services = Service::query()
            ->whereIn('type', $sqlTypes)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'label', 'type']);

        if ($services->isEmpty()) {
            return [
                'summary'  => $this->emptyServiceSummary(),
                'services' => [],
            ];
        }

        $serviceIds = $services->pluck('id')->all();

        // Mode rows, keyed by service_id.
        $modes = SchemaContractService::query()
            ->whereIn('service_id', $serviceIds)
            ->get()
            ->keyBy('service_id');

        // Snapshot aggregates, keyed by service_id.
        $aggregates = SchemaContractSnapshot::query()
            ->whereIn('service_id', $serviceIds)
            ->selectRaw('service_id,
                COUNT(*) AS total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS archived_count,
                MAX(last_modified_date) AS latest_change', [
                    SchemaContractSnapshot::STATUS_ACTIVE,
                    SchemaContractSnapshot::STATUS_ARCHIVED,
                ])
            ->groupBy('service_id')
            ->get()
            ->keyBy('service_id');

        $entries = [];
        $servicesConfigured = 0;
        $servicesLocked     = 0;
        $snapshotsTotal     = 0;

        foreach ($services as $svc) {
            $modeRow   = $modes->get($svc->id);
            $aggregate = $aggregates->get($svc->id);

            $active   = $aggregate ? (int) $aggregate->active_count   : 0;
            $archived = $aggregate ? (int) $aggregate->archived_count : 0;
            $total    = $aggregate ? (int) $aggregate->total          : 0;

            if ($modeRow) {
                $servicesConfigured++;
            }
            if ($active > 0) {
                $servicesLocked++;
            }
            $snapshotsTotal += $total;

            $entries[] = [
                'id'              => (int) $svc->id,
                'name'            => $svc->name,
                'label'           => $svc->label,
                'type'            => $svc->type,
                'mode'            => $modeRow?->mode ?? SchemaContractService::MODE_NONE,
                'configured'      => $modeRow !== null,
                'snapshot_counts' => [
                    'active'   => $active,
                    'archived' => $archived,
                    'total'    => $total,
                ],
                'latest_change'   => $aggregate?->latest_change,
            ];
        }

        return [
            'summary' => [
                'services_total'      => count($entries),
                'services_configured' => $servicesConfigured,
                'services_locked'     => $servicesLocked,
                'snapshots_total'     => $snapshotsTotal,
            ],
            'services' => $entries,
        ];
    }

    private function emptyServiceSummary(): array
    {
        return [
            'services_total'      => 0,
            'services_configured' => 0,
            'services_locked'     => 0,
            'snapshots_total'     => 0,
        ];
    }

    /**
     * Per-service rollup: mode, snapshot counts, and a drift summary
     * (counts only, no per-table change lists). Calls describeService
     * once when there are active snapshots; cheap-paths to no-drift
     * when nothing is locked.
     */
    private function getServiceSummary(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();

        $modeRow = SchemaContractService::query()
            ->where('service_id', $serviceId)
            ->first();

        $activeSnapshots = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->get();

        $archivedCount = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ARCHIVED)
            ->count();

        $latestPromotion = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->max('created_date');

        $base = [
            'service' => $serviceName,
            'mode'    => $modeRow?->mode ?? SchemaContractService::MODE_NONE,
            'runtime_enforcement' => $modeRow?->runtime_enforcement ?? SchemaContractService::ENFORCE_OFF,
            'archive_retention_count' => $modeRow?->archive_retention_count,
            'snapshot_counts' => [
                'active'   => $activeSnapshots->count(),
                'archived' => $archivedCount,
                'total'    => $activeSnapshots->count() + $archivedCount,
            ],
            'latest_promotion' => $latestPromotion,
        ];

        if ($activeSnapshots->isEmpty()) {
            return array_merge($base, [
                'drift' => [
                    'has_drift'           => false,
                    'has_breaking'        => false,
                    'tables_with_drift'   => 0,
                    'tables_with_breaking' => 0,
                    'summary'             => [
                        'breaking_count'             => 0,
                        'potentially_breaking_count' => 0,
                        'additive_count'             => 0,
                        'cosmetic_count'             => 0,
                        'total_changes'              => 0,
                    ],
                ],
            ]);
        }

        // We have active snapshots — describe live and compute drift.
        try {
            $adapter  = app(AdapterRegistry::class)->for($service);
            $envelope = $adapter->describeService($service);
        } catch (\Throwable $e) {
            return array_merge($base, [
                'drift' => null,
                'describe_error' => $e->getMessage(),
            ]);
        }

        $liveByIdentity = [];
        foreach (array_merge($envelope->tables, $envelope->views) as $tbl) {
            $arr = json_decode(json_encode($tbl, JSON_UNESCAPED_SLASHES), true);
            $liveByIdentity[$this->identityKey($arr['catalog'] ?? null, $arr['schema'] ?? null, $arr['name'])] = $arr;
        }

        $engine = new DriftEngine();
        $totals = [
            'breaking_count'             => 0,
            'potentially_breaking_count' => 0,
            'additive_count'             => 0,
            'cosmetic_count'             => 0,
            'total_changes'              => 0,
        ];
        $tablesWithDrift    = 0;
        $tablesWithBreaking = 0;

        foreach ($activeSnapshots as $snap) {
            $key  = $this->identityKey($snap->table_catalog, $snap->table_schema, $snap->table_name);
            $live = $liveByIdentity[$key] ?? null;

            if ($live === null) {
                // Snapshot exists, live table is gone — counts as breaking.
                $tablesWithDrift++;
                $tablesWithBreaking++;
                $totals['breaking_count']++;
                $totals['total_changes']++;
                continue;
            }

            $report = $engine->compareTable(json_decode($snap->schema_json, true), $live);
            if ($report['has_drift'])    { $tablesWithDrift++; }
            if ($report['has_breaking']) { $tablesWithBreaking++; }
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $report['summary'][$k];
            }
        }

        return array_merge($base, [
            'drift' => [
                'has_drift'            => $tablesWithDrift > 0,
                'has_breaking'         => $tablesWithBreaking > 0,
                'tables_with_drift'    => $tablesWithDrift,
                'tables_with_breaking' => $tablesWithBreaking,
                'summary'              => $totals,
            ],
        ]);
    }

    /**
     * Dashboard query: every table the service exposes, with lock and drift
     * status if a snapshot exists. Describes the service once (single
     * connector trip) and runs the drift engine in-memory to avoid N+1.
     *
     * Tables snapshotted but absent from live are surfaced with
     * `drift.has_breaking = true` (table_removed). Tables live but never
     * locked have `locked: false` and drift null.
     */
    private function listTables(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();

        $liveByIdentity = [];
        $describeError  = null;
        try {
            $adapter  = app(AdapterRegistry::class)->for($service);
            $envelope = $adapter->describeService($service);
            foreach (array_merge($envelope->tables, $envelope->views) as $tbl) {
                $arr = json_decode(json_encode($tbl, JSON_UNESCAPED_SLASHES), true);
                $liveByIdentity[$this->identityKey($arr['catalog'] ?? null, $arr['schema'] ?? null, $arr['name'])] = $arr;
            }
        } catch (\Throwable $e) {
            $describeError = $e->getMessage();
        }

        $snapshots = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->get()
            ->keyBy(fn ($s) => $this->identityKey($s->table_catalog, $s->table_schema, $s->table_name));

        $allKeys = array_values(array_unique(array_merge(
            array_keys($liveByIdentity),
            $snapshots->keys()->all()
        )));

        $engine = new DriftEngine();
        $entries = [];
        $tablesWithDrift = 0;
        $tablesWithBreaking = 0;

        foreach ($allKeys as $key) {
            $live = $liveByIdentity[$key] ?? null;
            $snap = $snapshots->get($key);

            // Catalog/schema/type can legitimately be null on the live side
            // (SQLite has no schema), so we have to check $snap !== null
            // before reaching into it rather than relying on `??`.
            $entry = [
                'name'     => $live['name']    ?? ($snap?->table_name    ?? '?'),
                'catalog'  => $live ? ($live['catalog'] ?? null) : $snap?->table_catalog,
                'schema'   => $live ? ($live['schema']  ?? null) : $snap?->table_schema,
                'type'     => $live ? ($live['type']    ?? TableSchema::TYPE_TABLE) : ($snap?->object_type ?? TableSchema::TYPE_TABLE),
                'locked'   => $snap !== null,
                'snapshot' => null,
                'drift'    => null,
            ];

            if ($snap) {
                $entry['snapshot'] = [
                    'version'      => (int) $snap->contract_version,
                    'hash'         => $snap->schema_hash,
                    'created_date' => $snap->created_date?->toIso8601String(),
                ];

                if ($live) {
                    $report = $engine->compareTable(json_decode($snap->schema_json, true), $live);
                } else {
                    // Locked table that no longer exists in the live DB.
                    $report = $engine->buildReport([[
                        'severity' => Severity::BREAKING,
                        'kind'     => Kind::TABLE_REMOVED,
                        'path'     => $snap->table_name,
                        'table'    => $snap->table_name,
                        'detail'   => [],
                    ]]);
                }

                $entry['drift'] = [
                    'has_drift'    => $report['has_drift'],
                    'has_breaking' => $report['has_breaking'],
                    'summary'      => $report['summary'],
                ];

                if ($report['has_drift'])    { $tablesWithDrift++; }
                if ($report['has_breaking']) { $tablesWithBreaking++; }
            }

            $entries[] = $entry;
        }

        usort($entries, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'service' => $serviceName,
            'mode'    => SchemaContractService::modeFor($serviceId),
            'summary' => [
                'tables_total'         => count($entries),
                'tables_locked'        => $snapshots->count(),
                'tables_with_drift'    => $tablesWithDrift,
                'tables_with_breaking' => $tablesWithBreaking,
            ],
            'tables'         => $entries,
            'describe_error' => $describeError,
        ];
    }

    /**
     * Service-wide drift report. Per SYSTEM_API.md Decision #2, full
     * candidate canonical JSON is omitted by default; the per-table `changes`
     * list is always inlined because it is small and is what CI gates and
     * dashboards consume. Pass `?include=candidate` to also get full
     * candidate JSON per table.
     */
    private function getServiceDiff(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();

        try {
            $adapter  = app(AdapterRegistry::class)->for($service);
            $envelope = $adapter->describeService($service);
        } catch (\Throwable $e) {
            throw new InternalServerErrorException("Describe failed for '{$serviceName}': " . $e->getMessage());
        }

        $liveByIdentity = [];
        foreach (array_merge($envelope->tables, $envelope->views) as $tbl) {
            $arr = json_decode(json_encode($tbl, JSON_UNESCAPED_SLASHES), true);
            $liveByIdentity[$this->identityKey($arr['catalog'] ?? null, $arr['schema'] ?? null, $arr['name'])] = $arr;
        }

        $snapshots = SchemaContractSnapshot::query()
            ->where('service_id', $serviceId)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->get();

        $includeCandidate = $this->request->getParameterAsBool('include_candidate')
            || $this->request->getParameter('include') === 'candidate';

        $engine        = new DriftEngine();
        $tableReports  = [];
        $totals = [
            'breaking_count'             => 0,
            'potentially_breaking_count' => 0,
            'additive_count'             => 0,
            'cosmetic_count'             => 0,
            'total_changes'              => 0,
        ];
        $tablesWithDrift    = 0;
        $tablesWithBreaking = 0;

        foreach ($snapshots as $snap) {
            $key  = $this->identityKey($snap->table_catalog, $snap->table_schema, $snap->table_name);
            $live = $liveByIdentity[$key] ?? null;

            if ($live === null) {
                $report = $engine->buildReport([[
                    'severity' => Severity::BREAKING,
                    'kind'     => Kind::TABLE_REMOVED,
                    'path'     => $snap->table_name,
                    'table'    => $snap->table_name,
                    'detail'   => ['old_table' => json_decode($snap->schema_json, true)],
                ]]);
            } else {
                $report = $engine->compareTable(json_decode($snap->schema_json, true), $live);
            }

            $tableEntry = [
                'name'                    => $snap->table_name,
                'catalog'                 => $snap->table_catalog,
                'schema'                  => $snap->table_schema,
                'active_snapshot_version' => (int) $snap->contract_version,
                'has_drift'               => $report['has_drift'],
                'has_breaking'            => $report['has_breaking'],
                'summary'                 => $report['summary'],
                'changes'                 => $report['changes'],
            ];

            if ($includeCandidate) {
                $tableEntry['candidate'] = $live;
            }

            $tableReports[] = $tableEntry;

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $report['summary'][$k];
            }
            if ($report['has_drift'])    { $tablesWithDrift++; }
            if ($report['has_breaking']) { $tablesWithBreaking++; }
        }

        usort($tableReports, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'service'               => $serviceName,
            'checked_at'            => gmdate('Y-m-d\TH:i:s\Z'),
            'active_snapshot_count' => $snapshots->count(),
            'summary'               => array_merge([
                'has_drift'           => $tablesWithDrift > 0,
                'has_breaking'        => $tablesWithBreaking > 0,
                'tables_with_drift'   => $tablesWithDrift,
                'tables_with_breaking' => $tablesWithBreaking,
            ], $totals),
            'tables' => $tableReports,
        ];
    }

    /**
     * Generate the OpenAPI schema object for one table.
     *
     * Source selection is mode-aware (the Phase 2 payoff):
     *   - mode `auto`/`strict` with an active snapshot → schema from the
     *     LOCKED contract (stable; doesn't move when the DB drifts)
     *   - otherwise → schema from LIVE describe
     *
     * Response carries `source` and `snapshot_version` so callers know
     * whether they're looking at a frozen contract or live schema.
     */
    private function getTableOpenApi(string $serviceName, string $tableName): array
    {
        $service = $this->resolveSqlService($serviceName);

        [$canonical, $source, $version] = $this->resolveCanonicalForTable($service, $tableName);

        $generator  = new OpenApiSchemaGenerator();
        $schemaName = $generator->schemaName($serviceName, $canonical);

        return [
            'service'          => $serviceName,
            'table'            => $tableName,
            'source'           => $source,
            'snapshot_version' => $version,
            'schema_name'      => $schemaName,
            'schema'           => $generator->fromCanonicalTable($canonical),
        ];
    }

    /**
     * Generate an OpenAPI `components/schemas` block for the whole service.
     * Each table's source is mode-aware in the same way as the single-table
     * endpoint. The `sources` map records, per schema name, whether it came
     * from a snapshot (and which version) or from live.
     */
    private function getServiceOpenApi(string $serviceName): array
    {
        $service   = $this->resolveSqlService($serviceName);
        $serviceId = $service->getServiceId();
        $mode      = SchemaContractService::modeFor($serviceId);
        $useLocked = in_array($mode, [SchemaContractService::MODE_AUTO, SchemaContractService::MODE_STRICT], true);

        try {
            $adapter  = app(AdapterRegistry::class)->for($service);
            $envelope = $adapter->describeService($service);
        } catch (\Throwable $e) {
            throw new InternalServerErrorException(
                "Describe failed for '{$serviceName}': " . $e->getMessage()
            );
        }

        // Live canonical, keyed by identity.
        $liveByIdentity = [];
        foreach (array_merge($envelope->tables, $envelope->views) as $tbl) {
            $arr = json_decode(json_encode($tbl, JSON_UNESCAPED_SLASHES), true);
            $liveByIdentity[$this->identityKey($arr['catalog'] ?? null, $arr['schema'] ?? null, $arr['name'])] = $arr;
        }

        // Active snapshots, keyed by identity (only consulted in locked modes).
        $snapshotsByIdentity = [];
        if ($useLocked) {
            $snapshots = SchemaContractSnapshot::query()
                ->where('service_id', $serviceId)
                ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
                ->get();
            foreach ($snapshots as $snap) {
                $snapshotsByIdentity[$this->identityKey($snap->table_catalog, $snap->table_schema, $snap->table_name)] = $snap;
            }
        }

        $generator = new OpenApiSchemaGenerator();
        $schemas   = [];
        $sources   = [];

        foreach ($liveByIdentity as $key => $liveTable) {
            $snap = $snapshotsByIdentity[$key] ?? null;

            if ($snap) {
                $canonical = json_decode($snap->schema_json, true);
                $sourceMeta = ['source' => 'snapshot', 'version' => (int) $snap->contract_version];
            } else {
                $canonical = $liveTable;
                $sourceMeta = ['source' => 'live', 'version' => null];
            }

            $name = $generator->schemaName($serviceName, $canonical);
            $schemas[$name] = $generator->fromCanonicalTable($canonical);
            $sources[$name] = $sourceMeta;
        }

        return [
            'service'    => $serviceName,
            'mode'       => $mode,
            'components' => ['schemas' => $schemas],
            'sources'    => $sources,
        ];
    }

    /**
     * Resolve the canonical table to use for OpenAPI/preview purposes,
     * honoring contract mode. Returns [canonicalArray, source, version]
     * where source is 'snapshot' or 'live' and version is the snapshot
     * version (or null for live).
     *
     * @return array{0: array, 1: string, 2: int|null}
     */
    private function resolveCanonicalForTable(ServiceInterface $service, string $tableName): array
    {
        $serviceId = $service->getServiceId();
        $mode      = SchemaContractService::modeFor($serviceId);
        $useLocked = in_array($mode, [SchemaContractService::MODE_AUTO, SchemaContractService::MODE_STRICT], true);

        if ($useLocked) {
            $snapshot = SchemaContractSnapshot::query()
                ->where('service_id', $serviceId)
                ->where('table_name', $tableName)
                ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
                ->orderByDesc('contract_version')
                ->first();

            if ($snapshot) {
                return [json_decode($snapshot->schema_json, true), 'snapshot', (int) $snapshot->contract_version];
            }
        }

        // Fall through to live describe.
        try {
            $adapter   = app(AdapterRegistry::class)->for($service);
            $canonical = $adapter->describeTable($service, $tableName);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                throw new NotFoundException($e->getMessage());
            }
            throw new InternalServerErrorException($e->getMessage());
        }

        $arr = json_decode(json_encode($canonical, JSON_UNESCAPED_SLASHES), true);
        return [$arr, 'live', null];
    }

    /**
     * Build an identity key from the canonical (catalog, schema, name) tuple
     * used to match a snapshot to a live table.
     */
    private function identityKey(?string $catalog, ?string $schema, string $name): string
    {
        return ($catalog ?? '') . "\x00" . ($schema ?? '') . "\x00" . $name;
    }

    /**
     * Resolve a service by name and confirm it is a SQL service the
     * canonical-schema pipeline can describe. Returns the live service.
     */
    private function resolveSqlService(string $name): ServiceInterface
    {
        $service = ServiceManager::getService($name);
        if (!$service) {
            throw new NotFoundException("Service '{$name}' not found.");
        }
        // NoSQL DB services (MongoDB, Cassandra, ...) also extend
        // BaseDbService, so check the type allowlist for a clean rejection
        // rather than letting the request fall through to the adapter
        // registry with a generic "no adapter" error.
        $isSql = $service instanceof BaseDbService
            && in_array(strtolower((string) $service->getType()), DefaultSqlAdapter::SQL_SERVICE_TYPES, true);
        if (!$isSql) {
            throw new BadRequestException(
                "Service '{$name}' is not a SQL database service; schema contracts are SQL-only."
            );
        }
        return $service;
    }

    /**
     * Shape a snapshot row for the JSON response. The stored schema_json is
     * decoded to a real object so clients don't have to parse twice; the
     * raw string is preserved under `schema_json_raw` if needed for hashing.
     */
    private function formatSnapshot(SchemaContractSnapshot $snap, ?string $lockResult): array
    {
        return [
            'id'                => (int) $snap->id,
            'service_id'        => (int) $snap->service_id,
            'service_name'      => $snap->service_name,
            'table_catalog'     => $snap->table_catalog,
            'table_schema'      => $snap->table_schema,
            'table_name'        => $snap->table_name,
            'object_type'       => $snap->object_type,
            'contract_version'  => (int) $snap->contract_version,
            'schema_hash'       => $snap->schema_hash,
            'status'            => $snap->status,
            'created_date'      => $snap->created_date?->toIso8601String(),
            'last_modified_date'=> $snap->last_modified_date?->toIso8601String(),
            'lock_result'       => $lockResult,
            'schema'            => json_decode($snap->schema_json, true),
        ];
    }
}
