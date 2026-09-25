<?php

namespace DreamFactory\Core\SchemaContracts\Enforcement;

/**
 * Pure rules for shaping data to a table's active contract, shared by the
 * response filter (EnforcementEventHandler) and the data model enricher.
 */
final class ContractShape
{
    /** Aggregates DreamFactory computes from ?fields=SUM(col) etc. */
    private const AGGREGATES = ['SUM', 'COUNT', 'AVG', 'MIN', 'MAX'];

    /**
     * Allowed response keys for a table's canonical contract: field names and
     * aliases (DreamFactory keys records by alias when one is set) plus
     * relationship names and aliases (so ?related= results are not stripped).
     *
     * @param array<string, mixed> $canonical
     * @return array<string, bool>
     */
    public static function allowedKeys(array $canonical): array
    {
        $keys = [];
        foreach (array_merge($canonical['fields'] ?? [], $canonical['relationships'] ?? []) as $item) {
            foreach ([$item['alias'] ?? null, $item['name'] ?? null] as $k) {
                if (is_string($k) && $k !== '') {
                    $keys[$k] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Whether a record key is an aggregate DreamFactory computed over an
     * allowed column: COUNT_ALL for COUNT(*), else {FUNC}_{column}, the alias
     * df-sqldb gives SUM(col), AVG(col), ... in ?fields=. Such keys are not
     * contract fields but carry only a value derived from ones that are.
     *
     * @param array<string, bool> $allowed
     */
    public static function isAggregateOfAllowed(string $key, array $allowed): bool
    {
        if ($key === 'COUNT_ALL') {
            return true;
        }
        if (!preg_match('/^(' . implode('|', self::AGGREGATES) . ')_(.+)$/', $key, $m)) {
            return false;
        }
        foreach (array_keys($allowed) as $name) {
            if (preg_replace('/[^a-zA-Z0-9_]/', '_', $name) === $m[2]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only allowed keys, plus aggregates computed over allowed columns.
     *
     * @param array<string, mixed> $record
     * @param array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    public static function filterTopLevel(array $record, array $allowed): array
    {
        $out = [];
        foreach ($record as $k => $v) {
            if (isset($allowed[$k]) || self::isAggregateOfAllowed((string) $k, $allowed)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Shape one table entry of the condensed data model (_spec?model=true) to
     * the contract: columns, sample rows, enum values, value meanings and
     * relationships that the contract does not expose are removed, so an
     * agent is never told about data the API will not return.
     *
     * @param array<string, mixed> $entry
     * @param array<string, bool>  $allowed
     * @return array<string, mixed>
     */
    public static function shapeModelTable(array $entry, array $allowed): array
    {
        // A model column may be listed under its API name (alias) with the database
        // name in `column`; if the contract allows either, samples and enum values
        // keyed by the API name must survive too.
        foreach ($entry['columns'] ?? [] as $c) {
            if (isset($c['column'], $c['name']) && (isset($allowed[(string) $c['column']]) || isset($allowed[(string) $c['name']]))) {
                $allowed[(string) $c['name']] = true;
            }
        }
        if (isset($entry['columns']) && is_array($entry['columns'])) {
            $entry['columns'] = array_values(array_filter(
                $entry['columns'],
                static fn ($c) => isset($allowed[(string) ($c['name'] ?? '')]) || isset($allowed[(string) ($c['column'] ?? '')])
            ));
        }
        if (isset($entry['sample_data']) && is_array($entry['sample_data'])) {
            $entry['sample_data'] = array_map(
                static fn ($row) => is_array($row) ? array_intersect_key($row, $allowed) : $row,
                $entry['sample_data']
            );
        }
        if (isset($entry['enum_values']) && is_array($entry['enum_values'])) {
            $entry['enum_values'] = array_intersect_key($entry['enum_values'], $allowed);
            if ($entry['enum_values'] === []) {
                unset($entry['enum_values']);
            }
        }

        return $entry;
    }
}
