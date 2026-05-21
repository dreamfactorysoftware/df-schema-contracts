<?php

namespace DreamFactory\Core\SchemaContracts\Drift;

/**
 * Compares two canonical Table JSON documents (active snapshot vs live) and
 * produces a drift report.
 *
 * Inputs are array-decoded canonical Table JSON, not DTOs — snapshots are
 * stored as JSON strings, and converting back through DTOs adds no value for
 * comparison. The output shape matches the design in docs/SYSTEM_API.md
 * "Drift report payload".
 *
 * Rename detection is explicitly NOT performed: a renamed field surfaces as
 * `field.removed` + `field.added`. See canonical identity rules.
 */
class DriftEngine
{
    /**
     * @param array $active  Decoded canonical Table JSON from a snapshot.
     * @param array $live    Decoded canonical Table JSON from a live describe.
     * @return array         {has_drift, has_breaking, summary, changes}
     */
    public function compareTable(array $active, array $live): array
    {
        $tableName = $live['name'] ?? ($active['name'] ?? '?');
        $changes = [];

        $changes = array_merge($changes, $this->compareTableAttributes($tableName, $active, $live));
        $changes = array_merge($changes, $this->compareFields($tableName, $active['fields'] ?? [], $live['fields'] ?? []));
        $changes = array_merge($changes, $this->comparePrimaryKey($tableName, $active['primary_key'] ?? [], $live['primary_key'] ?? []));
        $changes = array_merge($changes, $this->compareRelationships($tableName, $active['relationships'] ?? [], $live['relationships'] ?? []));

        return $this->buildReport($changes);
    }

    /**
     * Build the summary-and-counts envelope around a flat change list.
     */
    public function buildReport(array $changes): array
    {
        $counts = [
            Severity::ADDITIVE             => 0,
            Severity::BREAKING             => 0,
            Severity::POTENTIALLY_BREAKING => 0,
            Severity::COSMETIC             => 0,
        ];

        foreach ($changes as $change) {
            $counts[$change['severity']]++;
        }

        return [
            'has_drift'    => count($changes) > 0,
            'has_breaking' => $counts[Severity::BREAKING] > 0,
            'summary'      => [
                'breaking_count'             => $counts[Severity::BREAKING],
                'potentially_breaking_count' => $counts[Severity::POTENTIALLY_BREAKING],
                'additive_count'             => $counts[Severity::ADDITIVE],
                'cosmetic_count'             => $counts[Severity::COSMETIC],
                'total_changes'              => count($changes),
            ],
            'changes' => $changes,
        ];
    }

    private function compareTableAttributes(string $tableName, array $a, array $l): array
    {
        $changes = [];

        if (($a['type'] ?? null) !== ($l['type'] ?? null)) {
            // table → view, view → materialized_view, etc. Always breaking.
            $changes[] = $this->change(
                Severity::BREAKING,
                Kind::TABLE_TYPE_CHANGED,
                $tableName, $tableName,
                ['from' => $a['type'] ?? null, 'to' => $l['type'] ?? null]
            );
        }

        if (($a['label'] ?? null) !== ($l['label'] ?? null)) {
            $changes[] = $this->change(
                Severity::COSMETIC,
                Kind::TABLE_LABEL_CHANGED,
                $tableName, $tableName,
                ['from' => $a['label'] ?? null, 'to' => $l['label'] ?? null]
            );
        }

        if (($a['description'] ?? null) !== ($l['description'] ?? null)) {
            $changes[] = $this->change(
                Severity::COSMETIC,
                Kind::TABLE_DESCRIPTION_CHANGED,
                $tableName, $tableName,
                ['from' => $a['description'] ?? null, 'to' => $l['description'] ?? null]
            );
        }

        return $changes;
    }

    private function compareFields(string $tableName, array $activeFields, array $liveFields): array
    {
        $changes = [];
        $active  = $this->indexByName($activeFields);
        $live    = $this->indexByName($liveFields);

        foreach ($live as $name => $field) {
            if (!isset($active[$name])) {
                $changes[] = $this->change(
                    Severity::ADDITIVE,
                    Kind::FIELD_ADDED,
                    "{$tableName}.{$name}",
                    $tableName,
                    ['new_field' => $field]
                );
            }
        }

        foreach ($active as $name => $field) {
            if (!isset($live[$name])) {
                $changes[] = $this->change(
                    Severity::BREAKING,
                    Kind::FIELD_REMOVED,
                    "{$tableName}.{$name}",
                    $tableName,
                    ['old_field' => $field]
                );
            }
        }

        foreach ($active as $name => $old) {
            if (!isset($live[$name])) {
                continue;
            }
            $changes = array_merge(
                $changes,
                $this->compareFieldAttrs($tableName, $name, $old, $live[$name])
            );
        }

        return $changes;
    }

    private function compareFieldAttrs(string $tableName, string $name, array $old, array $new): array
    {
        $changes = [];
        $path = "{$tableName}.{$name}";

        // Canonical type is the hardest break point.
        if (($old['type'] ?? null) !== ($new['type'] ?? null)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_TYPE_CHANGED, $path, $tableName,
                ['from' => $old['type'] ?? null, 'to' => $new['type'] ?? null]
            );
        }

        if (($old['element_type'] ?? null) !== ($new['element_type'] ?? null)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_ELEMENT_TYPE_CHANGED, $path, $tableName,
                ['from' => $old['element_type'] ?? null, 'to' => $new['element_type'] ?? null]
            );
        }

        $oldNull = (bool) ($old['allow_null'] ?? false);
        $newNull = (bool) ($new['allow_null'] ?? false);
        if ($oldNull !== $newNull) {
            $changes[] = $newNull
                ? $this->change(Severity::ADDITIVE, Kind::FIELD_NULLABLE_RELAXED,   $path, $tableName, ['from' => false, 'to' => true])
                : $this->change(Severity::BREAKING, Kind::FIELD_NULLABLE_TIGHTENED, $path, $tableName, ['from' => true,  'to' => false]);
        }

        // Length comparison only meaningful for string-shaped types. Per
        // canonical semantics, length is null for integer/boolean/id types.
        $stringLike = ['string', 'text', 'binary'];
        if (in_array($old['type'] ?? null, $stringLike, true) && in_array($new['type'] ?? null, $stringLike, true)) {
            $changes = array_merge($changes, $this->compareSizeAttr(
                $path, $tableName, $old['length'] ?? null, $new['length'] ?? null,
                Kind::FIELD_LENGTH_REDUCED, Kind::FIELD_LENGTH_INCREASED
            ));
        }

        $changes = array_merge($changes, $this->compareSizeAttr(
            $path, $tableName, $old['precision'] ?? null, $new['precision'] ?? null,
            Kind::FIELD_PRECISION_REDUCED, Kind::FIELD_PRECISION_INCREASED
        ));
        $changes = array_merge($changes, $this->compareSizeAttr(
            $path, $tableName, $old['scale'] ?? null, $new['scale'] ?? null,
            Kind::FIELD_SCALE_REDUCED, Kind::FIELD_SCALE_INCREASED
        ));

        $oldDefault = $old['default'] ?? null;
        $newDefault = $new['default'] ?? null;
        if ($oldDefault !== $newDefault) {
            if ($oldDefault !== null && $newDefault === null) {
                // Removing a default makes inserts that omitted the field fail.
                $changes[] = $this->change(
                    Severity::POTENTIALLY_BREAKING, Kind::FIELD_DEFAULT_REMOVED, $path, $tableName,
                    ['from' => $oldDefault, 'to' => null]
                );
            } else {
                $changes[] = $this->change(
                    Severity::ADDITIVE, Kind::FIELD_DEFAULT_CHANGED, $path, $tableName,
                    ['from' => $oldDefault, 'to' => $newDefault]
                );
            }
        }

        $oldReq = (bool) ($old['required'] ?? false);
        $newReq = (bool) ($new['required'] ?? false);
        if ($oldReq !== $newReq) {
            $changes[] = $newReq
                ? $this->change(Severity::BREAKING, Kind::FIELD_REQUIRED_ADDED,   $path, $tableName, ['from' => false, 'to' => true])
                : $this->change(Severity::ADDITIVE, Kind::FIELD_REQUIRED_REMOVED, $path, $tableName, ['from' => true,  'to' => false]);
        }

        $oldAuto = (bool) ($old['auto_increment'] ?? false);
        $newAuto = (bool) ($new['auto_increment'] ?? false);
        if ($oldAuto !== $newAuto) {
            $changes[] = $newAuto
                ? $this->change(Severity::ADDITIVE, Kind::FIELD_AUTO_INCREMENT_ADDED,   $path, $tableName, ['from' => false, 'to' => true])
                : $this->change(Severity::BREAKING, Kind::FIELD_AUTO_INCREMENT_REMOVED, $path, $tableName, ['from' => true,  'to' => false]);
        }

        $oldRO = (bool) ($old['read_only'] ?? false);
        $newRO = (bool) ($new['read_only'] ?? false);
        if ($oldRO !== $newRO) {
            $changes[] = $newRO
                ? $this->change(Severity::BREAKING, Kind::FIELD_READ_ONLY_ADDED,   $path, $tableName, ['from' => false, 'to' => true])
                : $this->change(Severity::ADDITIVE, Kind::FIELD_READ_ONLY_REMOVED, $path, $tableName, ['from' => true,  'to' => false]);
        }

        if (($old['generated'] ?? null) != ($new['generated'] ?? null)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_GENERATED_CHANGED, $path, $tableName,
                ['from' => $old['generated'] ?? null, 'to' => $new['generated'] ?? null]
            );
        }

        $oldUnique = (bool) ($old['is_unique'] ?? false);
        $newUnique = (bool) ($new['is_unique'] ?? false);
        if ($oldUnique !== $newUnique) {
            $changes[] = $newUnique
                ? $this->change(Severity::POTENTIALLY_BREAKING, Kind::FIELD_UNIQUE_ADDED,   $path, $tableName, ['from' => false, 'to' => true])
                : $this->change(Severity::ADDITIVE,             Kind::FIELD_UNIQUE_REMOVED, $path, $tableName, ['from' => true,  'to' => false]);
        }

        if (($old['is_foreign_key'] ?? false) !== ($new['is_foreign_key'] ?? false)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_FOREIGN_KEY_CHANGED, $path, $tableName,
                ['from' => (bool) ($old['is_foreign_key'] ?? false), 'to' => (bool) ($new['is_foreign_key'] ?? false)]
            );
        }

        if (($old['ref'] ?? null) != ($new['ref'] ?? null)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_REF_CHANGED, $path, $tableName,
                ['from' => $old['ref'] ?? null, 'to' => $new['ref'] ?? null]
            );
        }

        $changes = array_merge($changes, $this->compareEnum($path, $tableName, $old['enum'] ?? null, $new['enum'] ?? null));

        if (($old['validation'] ?? null) != ($new['validation'] ?? null)) {
            $changes[] = $this->change(
                Severity::POTENTIALLY_BREAKING, Kind::FIELD_VALIDATION_CHANGED, $path, $tableName,
                ['from' => $old['validation'] ?? null, 'to' => $new['validation'] ?? null]
            );
        }

        if (($old['label'] ?? null) !== ($new['label'] ?? null)) {
            $changes[] = $this->change(
                Severity::COSMETIC, Kind::FIELD_LABEL_CHANGED, $path, $tableName,
                ['from' => $old['label'] ?? null, 'to' => $new['label'] ?? null]
            );
        }

        if (($old['description'] ?? null) !== ($new['description'] ?? null)) {
            $changes[] = $this->change(
                Severity::COSMETIC, Kind::FIELD_DESCRIPTION_CHANGED, $path, $tableName,
                ['from' => $old['description'] ?? null, 'to' => $new['description'] ?? null]
            );
        }

        return $changes;
    }

    /**
     * Numeric "size-like" attribute comparison (length, precision, scale).
     * null → real and real → null both register as a direction; null is
     * treated as the lower bound for "reduced" purposes.
     */
    private function compareSizeAttr(
        string $path, string $tableName, $oldVal, $newVal, string $reducedKind, string $increasedKind
    ): array {
        if ($oldVal === $newVal) {
            return [];
        }
        $oldN = $oldVal ?? 0;
        $newN = $newVal ?? 0;
        if ($newN > $oldN) {
            return [$this->change(Severity::ADDITIVE, $increasedKind, $path, $tableName, ['from' => $oldVal, 'to' => $newVal])];
        }
        return [$this->change(Severity::POTENTIALLY_BREAKING, $reducedKind, $path, $tableName, ['from' => $oldVal, 'to' => $newVal])];
    }

    private function compareEnum(string $path, string $tableName, ?array $old, ?array $new): array
    {
        $oldVals = $old['values'] ?? null;
        $newVals = $new['values'] ?? null;

        if ($oldVals === null && $newVals === null) {
            return [];
        }
        if ($oldVals === null) {
            // ENUM constraint added — every previously valid value is still
            // valid, so additive. (Customers who want to flag this as
            // potentially_breaking can rely on field.validation_changed
            // semantics if needed.)
            return [$this->change(Severity::ADDITIVE, Kind::FIELD_ENUM_VALUE_ADDED, $path, $tableName,
                ['from' => null, 'to' => $newVals])];
        }
        if ($newVals === null) {
            return [$this->change(Severity::ADDITIVE, Kind::FIELD_ENUM_VALUE_REMOVED, $path, $tableName,
                ['from' => $oldVals, 'to' => null])];
        }

        $added   = array_values(array_diff($newVals, $oldVals));
        $removed = array_values(array_diff($oldVals, $newVals));

        $changes = [];
        if (!empty($added)) {
            // Adding a value can break clients that validate against the
            // existing list — see SYSTEM_API.md severity table.
            $changes[] = $this->change(
                Severity::POTENTIALLY_BREAKING, Kind::FIELD_ENUM_VALUE_ADDED, $path, $tableName,
                ['added' => $added, 'from' => $oldVals, 'to' => $newVals]
            );
        }
        if (!empty($removed)) {
            $changes[] = $this->change(
                Severity::BREAKING, Kind::FIELD_ENUM_VALUE_REMOVED, $path, $tableName,
                ['removed' => $removed, 'from' => $oldVals, 'to' => $newVals]
            );
        }
        return $changes;
    }

    private function comparePrimaryKey(string $tableName, $oldPk, $newPk): array
    {
        $oldArr = array_values((array) $oldPk);
        $newArr = array_values((array) $newPk);
        if ($oldArr === $newArr) {
            return [];
        }
        return [$this->change(
            Severity::BREAKING, Kind::PRIMARY_KEY_CHANGED, "{$tableName}.primary_key", $tableName,
            ['from' => $oldArr, 'to' => $newArr]
        )];
    }

    private function compareRelationships(string $tableName, array $activeRels, array $liveRels): array
    {
        $changes = [];
        $active  = $this->indexByName($activeRels);
        $live    = $this->indexByName($liveRels);

        foreach ($live as $name => $rel) {
            if (!isset($active[$name])) {
                $changes[] = $this->change(
                    Severity::ADDITIVE, Kind::RELATIONSHIP_ADDED,
                    "{$tableName}.{$name}", $tableName,
                    ['new_relationship' => $rel]
                );
            }
        }

        foreach ($active as $name => $rel) {
            if (!isset($live[$name])) {
                $changes[] = $this->change(
                    Severity::BREAKING, Kind::RELATIONSHIP_REMOVED,
                    "{$tableName}.{$name}", $tableName,
                    ['old_relationship' => $rel]
                );
            }
        }

        foreach ($active as $name => $old) {
            if (!isset($live[$name])) {
                continue;
            }
            $new = $live[$name];
            $path = "{$tableName}.{$name}";

            if (($old['type'] ?? null) !== ($new['type'] ?? null)) {
                $changes[] = $this->change(
                    Severity::BREAKING, Kind::RELATIONSHIP_TYPE_CHANGED, $path, $tableName,
                    ['from' => $old['type'] ?? null, 'to' => $new['type'] ?? null]
                );
            }
            $targetKeys = ['ref_service', 'ref_schema', 'ref_table', 'ref_field', 'junction'];
            $oldTarget  = array_intersect_key($old, array_flip($targetKeys));
            $newTarget  = array_intersect_key($new, array_flip($targetKeys));
            if ($oldTarget != $newTarget) {
                $changes[] = $this->change(
                    Severity::BREAKING, Kind::RELATIONSHIP_TARGET_CHANGED, $path, $tableName,
                    ['from' => $oldTarget, 'to' => $newTarget]
                );
            }
            if (($old['label'] ?? null) !== ($new['label'] ?? null)) {
                $changes[] = $this->change(
                    Severity::COSMETIC, Kind::RELATIONSHIP_LABEL_CHANGED, $path, $tableName,
                    ['from' => $old['label'] ?? null, 'to' => $new['label'] ?? null]
                );
            }
        }

        return $changes;
    }

    private function indexByName(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (isset($item['name'])) {
                $out[$item['name']] = $item;
            }
        }
        return $out;
    }

    private function change(string $severity, string $kind, string $path, string $tableName, array $detail): array
    {
        return [
            'severity' => $severity,
            'kind'     => $kind,
            'path'     => $path,
            'table'    => $tableName,
            'detail'   => $detail,
        ];
    }
}
