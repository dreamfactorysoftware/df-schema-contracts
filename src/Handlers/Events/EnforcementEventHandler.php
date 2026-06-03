<?php

namespace DreamFactory\Core\SchemaContracts\Handlers\Events;

use DreamFactory\Core\Events\PostProcessApiEvent;
use DreamFactory\Core\Events\PreProcessApiEvent;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractSnapshot;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Phase 6 runtime enforcement.
 *
 * Two complementary behaviors gated by a service's `runtime_enforcement`:
 *
 *  - shape_response (and strict): on the POST-process event, strip response
 *    fields not in the table's active locked contract. A locked table's API
 *    output stays stable even when the live DB grows new columns — they stay
 *    invisible until the contract is re-locked/promoted.
 *
 *  - strict only: on the PRE-process event, reject writes (POST/PUT/PATCH)
 *    whose payload references fields outside the contract or writes to
 *    read-only contract fields. This stops clients from writing to columns
 *    that aren't part of the locked API surface.
 *
 * Design notes (validated against live event probes):
 *  - The event NAME is the reliable identifier; `$request->getService()` is
 *    empty on internal service-to-service calls. Name format is
 *    `{service}._table.{table}.{verb}.(pre|post)_process`.
 *  - Post-process fires with the LIVE response object, so we MUTATE it in
 *    place (`setContent`) rather than reassigning.
 *  - To reject a write we throw BadRequestException from the pre-process
 *    listener; DreamFactory renders it as a 400 error envelope.
 *  - Two events fire per request (a templated `{table_name}` variant and the
 *    resolved one); we skip the templated variant.
 *
 * Field aliases ARE handled (read + write): allowed-key sets include both a
 * field's canonical name and its alias. Contracts locked before the canonical
 * model captured `alias` must be re-locked to gain alias-aware enforcement.
 *
 * Related/nested READ records ARE shaped one level deep: embedded related
 * records (`?related=`) are filtered by the related table's own active
 * contract. Same-service relationships only; cross-service and deeper-than-
 * one-level nesting pass through unshaped.
 *
 * KNOWN LIMITATIONS (tracked for follow-up increments):
 *  - Cross-service related records are not shaped.
 *  - Nesting deeper than one level (related-of-related) is not shaped.
 *  - Nested WRITES (related records in a write payload) are not validated.
 */
class EnforcementEventHandler
{
    /** @var array<string,string> per-request memo: serviceName => enforcement level */
    protected array $enforcementMemo = [];

    /** @var array<string,array<string,bool>|null> per-request memo: "service|table" => allowed-key set (or null = no contract) */
    protected array $allowedKeysMemo = [];

    /** @var array<string,array{writable:array<string,bool>,readonly:array<string,bool>}|null> per-request memo for write validation */
    protected array $writeKeysMemo = [];

    /** @var array<string,array<string,array<string,bool>|null>> per-request memo: "service|table" => relKey => related allowed set */
    protected array $relatedShapesMemo = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PreProcessApiEvent::class, [static::class, 'handlePreProcess']);
        $events->listen(PostProcessApiEvent::class, [static::class, 'handlePostProcess']);
    }

    /**
     * Strict inbound write validation. Rejects POST/PUT/PATCH payloads that
     * reference fields outside the active contract or write read-only fields.
     * Only active when runtime_enforcement = strict.
     *
     * @throws BadRequestException
     */
    public function handlePreProcess(PreProcessApiEvent $event): void
    {
        $parsed = $this->parseTableEvent((string) $event->name, $event->resource);
        if ($parsed === null) {
            return;
        }
        [$service, $table, $verb] = $parsed;

        if (!in_array($verb, ['POST', 'PUT', 'PATCH'], true)) {
            return; // not a write
        }

        // Write validation is strict-only. shape_response governs reads.
        if ($this->enforcementLevel($service) !== SchemaContractService::ENFORCE_STRICT) {
            return;
        }

        $sets = $this->writeKeySets($service, $table);
        if ($sets === null) {
            return; // no active contract — nothing to enforce
        }

        $payload = $event->request->getPayloadData();
        if (!is_array($payload)) {
            return;
        }

        $records = $this->extractRecords($payload);
        foreach ($records as $i => $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach (array_keys($record) as $key) {
                if (isset($sets['readonly'][$key])) {
                    throw new BadRequestException(
                        "Schema contract: field '{$key}' on '{$service}/{$table}' is read-only "
                        . "under the active contract and cannot be written."
                    );
                }
                if (!isset($sets['writable'][$key])) {
                    throw new BadRequestException(
                        "Schema contract: field '{$key}' is not part of the active contract for "
                        . "'{$service}/{$table}'. Re-lock or promote the table to include it."
                    );
                }
            }
        }
    }

    public function handlePostProcess(PostProcessApiEvent $event): void
    {
        $parsed = $this->parseTableEvent((string) $event->name, $event->resource);
        if ($parsed === null) {
            return;
        }
        [$service, $table, $verb] = $parsed;

        // shape_response only affects reads.
        if ($verb !== 'GET') {
            return;
        }

        // Fast path: is enforcement on for this service?
        $level = $this->enforcementLevel($service);
        if ($level === SchemaContractService::ENFORCE_OFF) {
            return;
        }

        // Need an active contract for this table to enforce against.
        $allowed = $this->allowedKeys($service, $table);
        if ($allowed === null) {
            return; // no active snapshot — nothing to enforce
        }

        $response = $event->response;
        if ($response === null) {
            return;
        }

        $content = $response->getContent();
        if (!is_array($content)) {
            return;
        }

        // Per-relationship nested shaping: embedded related records
        // (`?related=`) are shaped by the RELATED table's own contract, so a
        // related table's non-contract columns don't leak through a parent
        // read. One level deep; same-service relationships only.
        $relatedShapes = $this->relatedShapes($service, $table);

        $shaped = $this->shapeContent($content, $allowed, $relatedShapes);
        if ($shaped !== null) {
            $response->setContent($shaped);
        }
    }

    /**
     * Strip non-contract keys from response records. Handles the wrapped
     * `{resource: [...]}` list shape and a bare single-record shape. Returns
     * the modified content, or null if nothing was shapeable (leave as-is).
     *
     * @param array<string,array<string,bool>|null> $relatedShapes relKey => related-table allowed set (null = pass through)
     */
    protected function shapeContent(array $content, array $allowed, array $relatedShapes = []): ?array
    {
        if (isset($content['resource']) && is_array($content['resource'])) {
            $content['resource'] = array_map(
                fn ($rec) => is_array($rec) ? $this->filterRecord($rec, $allowed, $relatedShapes) : $rec,
                $content['resource']
            );
            return $content;
        }

        // Bare single record: associative array that isn't a list. We only
        // shape it if at least one key is a known contract field, to avoid
        // mangling non-record responses (counts, errors, etc.).
        if ($this->isAssoc($content) && $this->looksLikeRecord($content, $allowed)) {
            return $this->filterRecord($content, $allowed, $relatedShapes);
        }

        return null;
    }

    /**
     * @param array<string,array<string,bool>|null> $relatedShapes
     */
    protected function filterRecord(array $record, array $allowed, array $relatedShapes = []): array
    {
        $out = array_intersect_key($record, $allowed);

        // Shape embedded related records by the related table's contract.
        foreach ($relatedShapes as $relKey => $relAllowed) {
            if ($relAllowed === null || !array_key_exists($relKey, $out)) {
                continue; // related table not locked, or relationship not embedded
            }
            $val = $out[$relKey];
            if (!is_array($val)) {
                continue;
            }
            if ($this->isAssoc($val)) {
                // belongs_to / has_one — single embedded record
                $out[$relKey] = array_intersect_key($val, $relAllowed);
            } else {
                // has_many / many_many — list of embedded records
                $out[$relKey] = array_map(
                    fn ($r) => is_array($r) ? array_intersect_key($r, $relAllowed) : $r,
                    $val
                );
            }
        }

        return $out;
    }

    /**
     * Map each of a table's relationship keys (name + alias) to the allowed-key
     * set of the RELATED table's active contract, for nested response shaping.
     * A relationship maps to null (pass-through, no shaping) when the related
     * table is unlocked or lives in a different service. Memoized per request.
     *
     * @return array<string,array<string,bool>|null>
     */
    protected function relatedShapes(string $service, string $table): array
    {
        $memoKey = $service . '|' . $table;
        if (array_key_exists($memoKey, $this->relatedShapesMemo)) {
            return $this->relatedShapesMemo[$memoKey];
        }

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_name', $service)
            ->where('table_name', $table)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$snapshot) {
            return $this->relatedShapesMemo[$memoKey] = [];
        }

        $canonical = json_decode($snapshot->schema_json, true);
        $map = [];
        foreach (($canonical['relationships'] ?? []) as $r) {
            $refTable = $r['ref_table'] ?? null;
            if ($refTable === null || $refTable === '') {
                continue;
            }
            $refService = $r['ref_service'] ?? null;
            // Cross-service relationships are not shaped in this increment.
            $shape = ($refService !== null && $refService !== $service)
                ? null
                : $this->allowedKeys($service, $refTable);

            foreach ([$r['name'] ?? null, $r['alias'] ?? null] as $k) {
                if (!empty($k)) {
                    $map[$k] = $shape;
                }
            }
        }

        return $this->relatedShapesMemo[$memoKey] = $map;
    }

    /**
     * Allowed response keys for a table = contract field names ∪ relationship
     * names (so `?related=` fetches aren't stripped). Returns null when there
     * is no active snapshot. Memoized per request.
     */
    protected function allowedKeys(string $service, string $table): ?array
    {
        $memoKey = $service . '|' . $table;
        if (array_key_exists($memoKey, $this->allowedKeysMemo)) {
            return $this->allowedKeysMemo[$memoKey];
        }

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_name', $service)
            ->where('table_name', $table)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$snapshot) {
            return $this->allowedKeysMemo[$memoKey] = null;
        }

        $canonical = json_decode($snapshot->schema_json, true);
        $keys = [];
        foreach (($canonical['fields'] ?? []) as $f) {
            // DreamFactory keys response records by a field's ALIAS when one
            // is set, otherwise by its NAME. Allow both so aliased contract
            // fields are not over-stripped. Snapshots locked before the
            // canonical model captured `alias` won't have it — re-lock such
            // tables to pick up alias-aware shaping.
            if (!empty($f['alias'])) {
                $keys[$f['alias']] = true;
            }
            if (isset($f['name'])) {
                $keys[$f['name']] = true;
            }
        }
        foreach (($canonical['relationships'] ?? []) as $r) {
            if (!empty($r['alias'])) {
                $keys[$r['alias']] = true;
            }
            if (isset($r['name'])) {
                $keys[$r['name']] = true;
            }
        }

        return $this->allowedKeysMemo[$memoKey] = $keys;
    }

    /**
     * Build write-validation key sets for a table from its active contract:
     *   writable  = name/alias of non-read-only fields + relationship names/aliases
     *   readonly  = name/alias of read-only contract fields (reject writes to these)
     * Returns null when there is no active snapshot. Memoized per request.
     *
     * @return array{writable:array<string,bool>,readonly:array<string,bool>}|null
     */
    protected function writeKeySets(string $service, string $table): ?array
    {
        $memoKey = $service . '|' . $table;
        if (array_key_exists($memoKey, $this->writeKeysMemo)) {
            return $this->writeKeysMemo[$memoKey];
        }

        $snapshot = SchemaContractSnapshot::query()
            ->where('service_name', $service)
            ->where('table_name', $table)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->orderByDesc('contract_version')
            ->first();

        if (!$snapshot) {
            return $this->writeKeysMemo[$memoKey] = null;
        }

        $canonical = json_decode($snapshot->schema_json, true);
        $writable = [];
        $readonly = [];
        foreach (($canonical['fields'] ?? []) as $f) {
            $names = [];
            if (!empty($f['alias'])) {
                $names[] = $f['alias'];
            }
            if (isset($f['name'])) {
                $names[] = $f['name'];
            }
            $target = !empty($f['read_only']) ? 'readonly' : 'writable';
            foreach ($names as $n) {
                ${$target}[$n] = true;
            }
        }
        // Relationship keys are allowed for nested writes (not validated deeper).
        foreach (($canonical['relationships'] ?? []) as $r) {
            if (!empty($r['alias'])) {
                $writable[$r['alias']] = true;
            }
            if (isset($r['name'])) {
                $writable[$r['name']] = true;
            }
        }

        return $this->writeKeysMemo[$memoKey] = ['writable' => $writable, 'readonly' => $readonly];
    }

    protected function enforcementLevel(string $service): string
    {
        if (!array_key_exists($service, $this->enforcementMemo)) {
            $this->enforcementMemo[$service] = SchemaContractService::enforcementForName($service);
        }
        return $this->enforcementMemo[$service];
    }

    protected function tableFromName(string $base, string $service): string
    {
        // base = {service}._table.{table}.{verb}
        $afterTable = substr($base, strlen($service . '._table.'));
        // strip trailing .{verb}
        $pos = strrpos($afterTable, '.');
        return $pos === false ? $afterTable : substr($afterTable, 0, $pos);
    }

    /**
     * Parse a (pre|post)_process event into [service, table, verb], or null
     * if it isn't a resolved `_table` operation we should act on.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    protected function parseTableEvent(string $name, mixed $resource): ?array
    {
        // Skip the templated duplicate event ("{table_name}") — the resolved
        // one fires alongside it.
        if (str_contains($name, '{')) {
            return null;
        }
        if (!str_contains($name, '._table.')) {
            return null;
        }

        $service = strstr($name, '._table.', true);
        if ($service === false || $service === '') {
            return null;
        }

        // {service}._table.{table}.{verb} after stripping the process suffix.
        $base = preg_replace('/\.(pre|post)_process$/', '', $name);
        $verb = strtoupper((string) substr(strrchr($base, '.'), 1));
        if ($verb === '') {
            return null;
        }

        // Prefer the resolved resource (handles schema-qualified dotted names).
        $table = is_string($resource) && $resource !== ''
            ? $resource
            : $this->tableFromName($base, $service);
        if ($table === '') {
            return null;
        }

        return [$service, $table, $verb];
    }

    /**
     * Pull the list of records from a write payload. Handles the wrapped
     * `{resource: [...]}` shape and a bare single record.
     *
     * @return array<int,mixed>
     */
    protected function extractRecords(array $payload): array
    {
        if (isset($payload['resource']) && is_array($payload['resource'])) {
            return $payload['resource'];
        }
        if ($this->isAssoc($payload)) {
            return [$payload]; // single bare record
        }
        return [];
    }

    protected function isAssoc(array $a): bool
    {
        if ($a === []) {
            return false;
        }
        return array_keys($a) !== range(0, count($a) - 1);
    }

    protected function looksLikeRecord(array $content, array $allowed): bool
    {
        foreach (array_keys($content) as $k) {
            if (isset($allowed[$k])) {
                return true;
            }
        }
        return false;
    }
}
