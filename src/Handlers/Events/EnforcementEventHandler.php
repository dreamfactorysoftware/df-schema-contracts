<?php

namespace DreamFactory\Core\SchemaContracts\Handlers\Events;

use DreamFactory\Core\Events\PostProcessApiEvent;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractSnapshot;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Phase 6 runtime enforcement — response shaping.
 *
 * Subscribes to the always-fired PostProcessApiEvent. When a SQL service has
 * `runtime_enforcement` set to `shape_response` (or `strict`) AND the
 * requested table has an active locked contract, strips any response fields
 * that are not part of the contract. This makes a locked table's API output
 * stable even when the live database grows new columns — new columns stay
 * invisible to clients until the contract is re-locked/promoted.
 *
 * Design notes (validated against a live event probe):
 *  - The event NAME is the reliable identifier; `$request->getService()` is
 *    empty on internal service-to-service calls. Name format is
 *    `{service}._table.{table}.{verb}.post_process`.
 *  - DreamFactory fires the post-process event with the LIVE response object,
 *    so we MUTATE it in place (`setContent`) rather than reassigning — the
 *    dispatcher does not read a replaced response back.
 *  - Two events fire per request (a templated `{table_name}` variant and the
 *    resolved one); we skip the templated variant.
 *
 * Scope of this slice: outbound response shaping only, top-level table
 * records. Inbound write validation (`strict`) and related/nested record
 * shaping are deliberately not handled yet.
 *
 * Field aliases ARE handled: the allowed-key set includes both a field's
 * canonical name and its alias (DreamFactory keys response records by the
 * alias when set). Contracts locked before the canonical model captured
 * `alias` must be re-locked to gain alias-aware shaping.
 *
 * KNOWN LIMITATIONS (tracked for follow-up increments):
 *  - Related/nested records (`?related=`) below the top level are not shaped.
 *  - `?fields=` selection is not intersected with the contract.
 */
class EnforcementEventHandler
{
    /** @var array<string,string> per-request memo: serviceName => enforcement level */
    protected array $enforcementMemo = [];

    /** @var array<string,array<string,bool>|null> per-request memo: "service|table" => allowed-key set (or null = no contract) */
    protected array $allowedKeysMemo = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(PostProcessApiEvent::class, [static::class, 'handlePostProcess']);
    }

    public function handlePostProcess(PostProcessApiEvent $event): void
    {
        $name = (string) $event->name;

        // Skip the templated duplicate event ("{table_name}") — the resolved
        // one fires alongside it with the same response object.
        if (str_contains($name, '{')) {
            return;
        }

        // Only table operations: `{service}._table.{table}.{verb}.post_process`.
        if (!str_contains($name, '._table.')) {
            return;
        }

        // Parse service + verb from the event name.
        $service = strstr($name, '._table.', true);
        if ($service === false || $service === '') {
            return;
        }
        // verb is the segment immediately before `.post_process`.
        $base = preg_replace('/\.post_process$/', '', $name); // {service}._table.{table}.{verb}
        $verb = strtoupper((string) substr(strrchr($base, '.'), 1));

        // shape_response only affects reads.
        if ($verb !== 'GET') {
            return;
        }

        // Table name: prefer the resolved resource (handles schema-qualified
        // names with dots cleanly), fall back to parsing the event name.
        $table = is_string($event->resource) && $event->resource !== ''
            ? $event->resource
            : $this->tableFromName($base, $service);
        if ($table === '' ) {
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

        $shaped = $this->shapeContent($content, $allowed);
        if ($shaped !== null) {
            $response->setContent($shaped);
        }
    }

    /**
     * Strip non-contract keys from response records. Handles the wrapped
     * `{resource: [...]}` list shape and a bare single-record shape. Returns
     * the modified content, or null if nothing was shapeable (leave as-is).
     */
    protected function shapeContent(array $content, array $allowed): ?array
    {
        if (isset($content['resource']) && is_array($content['resource'])) {
            $content['resource'] = array_map(
                fn ($rec) => is_array($rec) ? $this->filterRecord($rec, $allowed) : $rec,
                $content['resource']
            );
            return $content;
        }

        // Bare single record: associative array that isn't a list. We only
        // shape it if at least one key is a known contract field, to avoid
        // mangling non-record responses (counts, errors, etc.).
        if ($this->isAssoc($content) && $this->looksLikeRecord($content, $allowed)) {
            return $this->filterRecord($content, $allowed);
        }

        return null;
    }

    protected function filterRecord(array $record, array $allowed): array
    {
        return array_intersect_key($record, $allowed);
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
